"""Bank Book: Bank Transactions (debit/credit entries + running ledger
balance) and Bank Reconciliation. See app/models/treasury.py's module
docstring for why this is a separate ledger from the General Ledger's
Journal Vouchers -- confirmed with Dennis, 2026-09-12.
"""
import uuid
from datetime import datetime, timezone
from decimal import Decimal

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.models.core import User
from app.models.groups import AccessLevel
from app.models.treasury import BankAccount, BankReconciliation, BankTransaction
from app.schemas.schemas import (
    BankLedgerOut,
    BankReconciliationCreate,
    BankReconciliationOut,
    BankTransactionCreate,
    BankTransactionOut,
    BankTransactionVoid,
)
from app.services import audit, bank_book
from app.services.authority import require_module_access
from app.services.numbering import next_document_number

router = APIRouter(tags=["bank-transactions"])
MODULE = "finance_accounting"


def _get_bank_account(db: Session, bank_account_id: uuid.UUID, company_id: uuid.UUID) -> BankAccount:
    bank_account = db.get(BankAccount, bank_account_id)
    if not bank_account or bank_account.company_id != company_id:
        raise HTTPException(status_code=404, detail="Bank account not found")
    return bank_account


def _get_transaction(db: Session, transaction_id: uuid.UUID, company_id: uuid.UUID) -> BankTransaction:
    txn = db.get(BankTransaction, transaction_id)
    if not txn or txn.company_id != company_id:
        raise HTTPException(status_code=404, detail="Bank transaction not found")
    return txn


def _transaction_out(txn: BankTransaction, running_balance_sgd: Decimal) -> BankTransactionOut:
    return BankTransactionOut(
        id=txn.id,
        bank_account_id=txn.bank_account_id,
        transaction_number=txn.transaction_number,
        transaction_date=txn.transaction_date,
        description=txn.description,
        reference=txn.reference,
        debit_sgd=float(txn.debit_sgd),
        credit_sgd=float(txn.credit_sgd),
        is_reconciled=txn.is_reconciled,
        reconciled_at=txn.reconciled_at,
        is_voided=txn.is_voided,
        void_reason=txn.void_reason,
        voided_at=txn.voided_at,
        created_at=txn.created_at,
        running_balance_sgd=float(running_balance_sgd),
        source_type=txn.source_type,
        source_id=txn.source_id,
    )


@router.get("/api/bank-accounts/{bank_account_id}/transactions", response_model=BankLedgerOut)
def list_bank_transactions(
    bank_account_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """The Bank Book ledger: every transaction in date order, each
    carrying a running balance starting from the account's opening
    balance. Voided lines stay visible (struck through in the UI) but
    don't move the running balance."""
    bank_account = _get_bank_account(db, bank_account_id, current_user.company_id)
    txns = (
        db.query(BankTransaction)
        .filter(BankTransaction.bank_account_id == bank_account.id)
        .order_by(BankTransaction.transaction_date, BankTransaction.created_at)
        .all()
    )

    rows: list[BankTransactionOut] = []
    running = Decimal(bank_account.opening_balance_sgd)
    unreconciled_count = 0
    for txn in txns:
        if not txn.is_voided:
            running += Decimal(txn.debit_sgd) - Decimal(txn.credit_sgd)
            if not txn.is_reconciled:
                unreconciled_count += 1
        rows.append(_transaction_out(txn, running))

    reconciled = bank_book.reconciled_balance(db, bank_account.id, bank_account.opening_balance_sgd)
    return BankLedgerOut(
        bank_account_id=bank_account.id,
        opening_balance_sgd=float(bank_account.opening_balance_sgd),
        opening_balance_date=bank_account.opening_balance_date,
        rows=rows,
        closing_balance_sgd=float(running),
        reconciled_balance_sgd=float(reconciled),
        unreconciled_count=unreconciled_count,
    )


@router.post("/api/bank-accounts/{bank_account_id}/transactions", response_model=BankTransactionOut)
def create_bank_transaction(
    bank_account_id: uuid.UUID,
    payload: BankTransactionCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    bank_account = _get_bank_account(db, bank_account_id, current_user.company_id)

    debit = Decimal(str(payload.debit_sgd))
    credit = Decimal(str(payload.credit_sgd))
    if debit > 0 and credit > 0:
        raise HTTPException(status_code=422, detail="A transaction is either a debit or a credit, not both.")
    if debit == 0 and credit == 0:
        raise HTTPException(status_code=422, detail="Enter a debit or a credit amount.")

    txn = BankTransaction(
        company_id=current_user.company_id,
        bank_account_id=bank_account.id,
        transaction_number=next_document_number(db, company_id=current_user.company_id, doc_kind="bank_transaction"),
        transaction_date=payload.transaction_date,
        description=payload.description,
        reference=payload.reference,
        debit_sgd=debit,
        credit_sgd=credit,
        created_by_user_id=current_user.id,
    )
    db.add(txn)
    db.flush()
    audit.record(
        db,
        entity_type="bank_transaction",
        entity_id=txn.id,
        action="created",
        actor_user_id=current_user.id,
        details=f"{bank_account.bank_name} {bank_account.account_number}: {payload.description}",
        new_value={
            "transaction_number": txn.transaction_number,
            "debit_sgd": str(debit) if debit else None,
            "credit_sgd": str(credit) if credit else None,
        },
    )
    db.commit()
    db.refresh(txn)
    return _transaction_out(txn, bank_book.current_balance(db, bank_account))


@router.post("/api/bank-transactions/{transaction_id}/void", response_model=BankTransactionOut)
def void_bank_transaction(
    transaction_id: uuid.UUID,
    payload: BankTransactionVoid,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    txn = _get_transaction(db, transaction_id, current_user.company_id)
    if txn.is_voided:
        raise HTTPException(status_code=409, detail="That transaction is already voided.")

    txn.is_voided = True
    txn.void_reason = payload.reason
    txn.voided_at = datetime.now(timezone.utc)
    audit.record(
        db,
        entity_type="bank_transaction",
        entity_id=txn.id,
        action="voided",
        actor_user_id=current_user.id,
        old_value={"is_voided": False},
        new_value={"is_voided": True, "void_reason": payload.reason},
    )
    db.commit()
    db.refresh(txn)
    bank_account = db.get(BankAccount, txn.bank_account_id)
    return _transaction_out(txn, bank_book.current_balance(db, bank_account))


@router.post("/api/bank-transactions/{transaction_id}/toggle-reconciled", response_model=BankTransactionOut)
def toggle_bank_transaction_reconciled(
    transaction_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """A quick per-line correction -- ticking/unticking one transaction
    without redoing a whole Bank Reconciliation session. The formal
    session below is still how a reconciliation gets recorded/dated."""
    txn = _get_transaction(db, transaction_id, current_user.company_id)
    old = txn.is_reconciled
    txn.is_reconciled = not old
    txn.reconciled_at = datetime.now(timezone.utc) if txn.is_reconciled else None
    audit.record(
        db,
        entity_type="bank_transaction",
        entity_id=txn.id,
        action="reconciled" if txn.is_reconciled else "unreconciled",
        actor_user_id=current_user.id,
        old_value={"is_reconciled": old},
        new_value={"is_reconciled": txn.is_reconciled},
    )
    db.commit()
    db.refresh(txn)
    bank_account = db.get(BankAccount, txn.bank_account_id)
    return _transaction_out(txn, bank_book.current_balance(db, bank_account))


@router.get("/api/bank-accounts/{bank_account_id}/reconciliations", response_model=list[BankReconciliationOut])
def list_bank_reconciliations(
    bank_account_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    bank_account = _get_bank_account(db, bank_account_id, current_user.company_id)
    rows = (
        db.query(BankReconciliation)
        .filter(BankReconciliation.bank_account_id == bank_account.id)
        .order_by(BankReconciliation.statement_date.desc(), BankReconciliation.created_at.desc())
        .all()
    )
    names = {u.id: u.full_name for u in db.query(User).filter(User.company_id == current_user.company_id)}
    return [
        BankReconciliationOut(
            id=r.id,
            bank_account_id=r.bank_account_id,
            statement_date=r.statement_date,
            statement_balance_sgd=float(r.statement_balance_sgd),
            ledger_balance_sgd=float(r.ledger_balance_sgd),
            difference_sgd=float(r.difference_sgd),
            note=r.note,
            reconciled_by_user_id=r.reconciled_by_user_id,
            reconciled_by_name=names.get(r.reconciled_by_user_id),
            created_at=r.created_at,
        )
        for r in rows
    ]


@router.post("/api/bank-accounts/{bank_account_id}/reconciliations", response_model=BankReconciliationOut)
def create_bank_reconciliation(
    bank_account_id: uuid.UUID,
    payload: BankReconciliationCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Saves a Bank Reconciliation session: ticks off the listed
    transactions (anything already reconciled is left alone) and
    snapshots the ledger balance as at the statement date against the
    statement's own balance, so the difference is on permanent record."""
    bank_account = _get_bank_account(db, bank_account_id, current_user.company_id)

    for txn_id in payload.reconciled_transaction_ids:
        txn = db.get(BankTransaction, txn_id)
        if not txn or txn.bank_account_id != bank_account.id:
            raise HTTPException(status_code=400, detail="One of the transactions to reconcile was not found.")
        if not txn.is_reconciled:
            txn.is_reconciled = True
            txn.reconciled_at = datetime.now(timezone.utc)

    ledger_balance = bank_book.current_balance(db, bank_account, as_at=payload.statement_date)
    statement_balance = Decimal(str(payload.statement_balance_sgd))
    difference = statement_balance - ledger_balance

    reconciliation = BankReconciliation(
        company_id=current_user.company_id,
        bank_account_id=bank_account.id,
        statement_date=payload.statement_date,
        statement_balance_sgd=statement_balance,
        ledger_balance_sgd=ledger_balance,
        difference_sgd=difference,
        note=payload.note,
        reconciled_by_user_id=current_user.id,
    )
    db.add(reconciliation)
    db.flush()
    audit.record(
        db,
        entity_type="bank_reconciliation",
        entity_id=reconciliation.id,
        action="reconciled",
        actor_user_id=current_user.id,
        details=f"{bank_account.bank_name} {bank_account.account_number} as at {payload.statement_date}",
        new_value={
            "statement_balance_sgd": str(statement_balance),
            "ledger_balance_sgd": str(ledger_balance),
            "difference_sgd": str(difference),
        },
    )
    db.commit()
    db.refresh(reconciliation)
    return BankReconciliationOut(
        id=reconciliation.id,
        bank_account_id=reconciliation.bank_account_id,
        statement_date=reconciliation.statement_date,
        statement_balance_sgd=float(reconciliation.statement_balance_sgd),
        ledger_balance_sgd=float(reconciliation.ledger_balance_sgd),
        difference_sgd=float(reconciliation.difference_sgd),
        note=reconciliation.note,
        reconciled_by_user_id=reconciliation.reconciled_by_user_id,
        reconciled_by_name=current_user.full_name,
        created_at=reconciliation.created_at,
    )
