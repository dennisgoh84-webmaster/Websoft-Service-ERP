"""
General Ledger -- Journal Vouchers and the trial balance.

A Journal Voucher is the manual double entry: the person raising it
chooses the debit and credit accounts themselves. That is deliberate --
the posting rules for automatic entries (which account a sales invoice
credits, how GST output tax is posted) are still undecided (open item
4b.2), so nothing here assumes them on Dennis's behalf.
"""
import uuid
from datetime import date

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.models.accounting import JournalEntry, JournalStatus, VoucherType
from app.models.core import User
from app.models.groups import AccessLevel
from app.models.accounting import Account
from app.schemas.schemas import (
    GLTransactionRow,
    GLTransactions,
    JournalEntryCreate,
    JournalEntryOut,
    ReverseRequest,
    TrialBalance,
    TrialBalanceRow,
)
from app.services import audit, exports
from app.services import ledger as ledger_svc
from app.services.authority import require_module_access

router = APIRouter(prefix="/api/ledger", tags=["general-ledger"])
MODULE = "finance_accounting"

GL_TRANSACTION_EXPORT_FIELDS = [
    "voucher_number", "voucher_type", "entry_date", "narration",
    "line_description", "debit_sgd", "credit_sgd", "balance_sgd",
]

VOUCHER_EXPORT_FIELDS = [
    "voucher_number", "voucher_type", "entry_date", "narration", "status",
    "total_debit_sgd", "total_credit_sgd",
]
TRIAL_BALANCE_EXPORT_FIELDS = ["code", "name", "account_type", "debit_sgd", "credit_sgd", "balance_sgd"]


def _entry_or_404(db: Session, entry_id: uuid.UUID, company_id: uuid.UUID) -> JournalEntry:
    entry = (
        db.query(JournalEntry)
        .options(selectinload(JournalEntry.lines))
        .filter(JournalEntry.id == entry_id, JournalEntry.company_id == company_id)
        .first()
    )
    if not entry:
        raise HTTPException(status_code=404, detail="Voucher not found")
    return entry


def _filter_vouchers(
    db: Session,
    company_id: uuid.UUID,
    voucher_type: VoucherType | None,
    status: JournalStatus | None,
) -> list[JournalEntry]:
    query = (
        db.query(JournalEntry)
        .options(selectinload(JournalEntry.lines))
        .filter(JournalEntry.company_id == company_id)
    )
    if voucher_type:
        query = query.filter(JournalEntry.voucher_type == voucher_type)
    if status:
        query = query.filter(JournalEntry.status == status)
    return query.order_by(JournalEntry.entry_date.desc(), JournalEntry.created_at.desc()).all()


@router.get("/vouchers", response_model=list[JournalEntryOut])
def list_vouchers(
    voucher_type: VoucherType | None = None,
    status: JournalStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    entries = _filter_vouchers(db, current_user.company_id, voucher_type, status)
    return [JournalEntryOut.from_model(e) for e in entries]


def _voucher_row(entry: JournalEntry) -> dict:
    return {
        "voucher_number": entry.voucher_number,
        "voucher_type": entry.voucher_type.value,
        "entry_date": entry.entry_date.isoformat(),
        "narration": entry.narration,
        "status": entry.status.value,
        "total_debit_sgd": f"{float(entry.total_debit):.2f}",
        "total_credit_sgd": f"{float(entry.total_credit):.2f}",
    }


def _vouchers_for_export(
    db: Session,
    company_id: uuid.UUID,
    voucher_type: VoucherType | None,
    status: JournalStatus | None,
) -> list[dict]:
    entries = _filter_vouchers(db, company_id, voucher_type, status)
    return [_voucher_row(e) for e in entries]


@router.get("/vouchers/export.csv")
def export_vouchers_csv(
    voucher_type: VoucherType | None = None,
    status: JournalStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _vouchers_for_export(db, current_user.company_id, voucher_type, status)
    csv_text = exports.rows_to_csv(VOUCHER_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=vouchers.csv"},
    )


@router.get("/vouchers/export.xlsx")
def export_vouchers_excel(
    voucher_type: VoucherType | None = None,
    status: JournalStatus | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _vouchers_for_export(db, current_user.company_id, voucher_type, status)
    data = exports.rows_to_excel(VOUCHER_EXPORT_FIELDS, rows, sheet_name="Vouchers")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=vouchers.xlsx"},
    )


@router.get("/vouchers/{entry_id}", response_model=JournalEntryOut)
def get_voucher(
    entry_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    return JournalEntryOut.from_model(_entry_or_404(db, entry_id, current_user.company_id))


@router.post("/vouchers", response_model=JournalEntryOut)
def create_journal_voucher(
    payload: JournalEntryCreate,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.EDIT)),
):
    """Raise a Journal Voucher. Created as a draft unless `post` is set;
    either way it must balance before it can be posted."""
    try:
        entry = ledger_svc.create_journal_entry(
            db,
            company_id=current_user.company_id,
            entry_date=payload.entry_date,
            narration=payload.narration,
            voucher_type=VoucherType.JOURNAL,
            created_by_user_id=current_user.id,
            lines=[line.model_dump() for line in payload.lines],
        )
        if payload.post:
            ledger_svc.post_entry(db, entry, actor_user_id=current_user.id)
    except ledger_svc.LedgerRuleViolation as e:
        raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="journal_entry",
        entity_id=entry.id,
        action="posted" if payload.post else "created",
        actor_user_id=current_user.id,
        details=f"{entry.voucher_number}: {payload.narration}",
        new_value={
            "voucher_number": entry.voucher_number,
            "debit_sgd": str(entry.total_debit),
            "credit_sgd": str(entry.total_credit),
            "status": entry.status.value,
        },
    )
    db.commit()
    db.refresh(entry)
    return JournalEntryOut.from_model(entry)


@router.post("/vouchers/{entry_id}/post", response_model=JournalEntryOut)
def post_voucher(
    entry_id: uuid.UUID,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Post a draft to the ledger. Refuses anything that doesn't balance."""
    entry = _entry_or_404(db, entry_id, current_user.company_id)
    try:
        ledger_svc.post_entry(db, entry, actor_user_id=current_user.id)
    except ledger_svc.LedgerRuleViolation as e:
        raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="journal_entry",
        entity_id=entry.id,
        action="posted",
        actor_user_id=current_user.id,
        details=f"{entry.voucher_number}: SGD {entry.total_debit}",
        old_value={"status": "draft"},
        new_value={"status": "posted"},
    )
    db.commit()
    db.refresh(entry)
    return JournalEntryOut.from_model(entry)


@router.post("/vouchers/{entry_id}/reverse", response_model=JournalEntryOut)
def reverse_voucher(
    entry_id: uuid.UUID,
    payload: ReverseRequest,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.FULL)),
):
    """Reverse a posted voucher. The original stays exactly as it was --
    a mirror entry is written instead, so both the mistake and its
    correction remain on record."""
    entry = _entry_or_404(db, entry_id, current_user.company_id)
    try:
        reversal = ledger_svc.reverse_entry(
            db, entry, actor_user_id=current_user.id, reason=payload.reason
        )
    except ledger_svc.LedgerRuleViolation as e:
        raise HTTPException(status_code=422, detail=str(e))

    audit.record(
        db,
        entity_type="journal_entry",
        entity_id=entry.id,
        action="reversed",
        actor_user_id=current_user.id,
        reason=payload.reason,
        details=f"{entry.voucher_number} reversed by {reversal.voucher_number}",
        old_value={"status": "posted"},
        new_value={"status": "reversed", "reversal_voucher": reversal.voucher_number},
    )
    db.commit()
    db.refresh(reversal)
    return JournalEntryOut.from_model(reversal)


def _trial_balance_rows(db: Session, company_id: uuid.UUID, as_at: date | None) -> list[TrialBalanceRow]:
    rows = ledger_svc.account_balances(db, company_id, as_at)
    return [
        TrialBalanceRow(
            account_id=r["account_id"],
            code=r["code"],
            name=r["name"],
            account_type=r["account_type"],
            debit_sgd=float(r["debit_sgd"]),
            credit_sgd=float(r["credit_sgd"]),
            balance_sgd=float(r["balance_sgd"]),
        )
        for r in rows
    ]


@router.get("/trial-balance", response_model=TrialBalance)
def trial_balance(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """Posted debits and credits per account. Draft and reversed vouchers
    are excluded -- only posted entries are part of the ledger."""
    out = _trial_balance_rows(db, current_user.company_id, as_at)
    total_debit = sum(r.debit_sgd for r in out)
    total_credit = sum(r.credit_sgd for r in out)
    return TrialBalance(
        as_at=as_at,
        rows=out,
        total_debit=total_debit,
        total_credit=total_credit,
        # A trial balance that doesn't balance means something is wrong
        # with the ledger itself, so it is surfaced rather than hidden.
        is_balanced=round(total_debit, 2) == round(total_credit, 2),
    )


def _trial_balance_for_export(db: Session, company_id: uuid.UUID, as_at: date | None) -> list[dict]:
    return [
        {
            "code": r.code,
            "name": r.name,
            "account_type": r.account_type,
            "debit_sgd": f"{r.debit_sgd:.2f}",
            "credit_sgd": f"{r.credit_sgd:.2f}",
            "balance_sgd": f"{r.balance_sgd:.2f}",
        }
        for r in _trial_balance_rows(db, company_id, as_at)
    ]


@router.get("/trial-balance/export.csv")
def export_trial_balance_csv(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _trial_balance_for_export(db, current_user.company_id, as_at)
    csv_text = exports.rows_to_csv(TRIAL_BALANCE_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=trial-balance.csv"},
    )


@router.get("/trial-balance/export.xlsx")
def export_trial_balance_excel(
    as_at: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows = _trial_balance_for_export(db, current_user.company_id, as_at)
    data = exports.rows_to_excel(TRIAL_BALANCE_EXPORT_FIELDS, rows, sheet_name="Trial Balance")
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": "attachment; filename=trial-balance.xlsx"},
    )


# ── GL Transaction Ledger (account drill-down) ──


def _gl_transactions(
    db: Session,
    company_id: uuid.UUID,
    account_id: uuid.UUID,
    date_from: date | None,
    date_to: date | None,
) -> GLTransactions:
    account = db.get(Account, account_id)
    if not account or account.company_id != company_id:
        raise HTTPException(status_code=404, detail="Account not found")

    rows = ledger_svc.account_transactions(
        db, company_id, account_id, date_from, date_to
    )
    total_debit = sum(r["debit_sgd"] for r in rows)
    total_credit = sum(r["credit_sgd"] for r in rows)
    closing_balance = rows[-1]["balance_sgd"] if rows else 0.0

    return GLTransactions(
        account_id=account.id,
        account_code=account.code,
        account_name=account.name,
        account_type=account.account_type.value,
        date_from=date_from,
        date_to=date_to,
        rows=[GLTransactionRow(**r) for r in rows],
        total_debit=total_debit,
        total_credit=total_credit,
        closing_balance=closing_balance,
    )


@router.get("/transactions/{account_id}", response_model=GLTransactions)
def gl_transactions(
    account_id: uuid.UUID,
    date_from: date | None = None,
    date_to: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    """GL transaction ledger for one account -- every posted debit/credit
    with running balance. Use the account_id from the trial balance or
    chart of accounts."""
    return _gl_transactions(db, current_user.company_id, account_id, date_from, date_to)


def _gl_transactions_for_export(
    db: Session,
    company_id: uuid.UUID,
    account_id: uuid.UUID,
    date_from: date | None,
    date_to: date | None,
) -> tuple[list[dict], str]:
    result = _gl_transactions(db, company_id, account_id, date_from, date_to)
    filename = f"gl-{result.account_code}"
    return (
        [
            {
                "voucher_number": r.voucher_number,
                "voucher_type": r.voucher_type,
                "entry_date": r.entry_date.isoformat(),
                "narration": r.narration,
                "line_description": r.line_description or "",
                "debit_sgd": f"{r.debit_sgd:.2f}",
                "credit_sgd": f"{r.credit_sgd:.2f}",
                "balance_sgd": f"{r.balance_sgd:.2f}",
            }
            for r in result.rows
        ],
        filename,
    )


@router.get("/transactions/{account_id}/export.csv")
def export_gl_transactions_csv(
    account_id: uuid.UUID,
    date_from: date | None = None,
    date_to: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows, filename = _gl_transactions_for_export(
        db, current_user.company_id, account_id, date_from, date_to
    )
    csv_text = exports.rows_to_csv(GL_TRANSACTION_EXPORT_FIELDS, rows)
    return StreamingResponse(
        iter([csv_text]),
        media_type="text/csv",
        headers={"Content-Disposition": f"attachment; filename={filename}.csv"},
    )


@router.get("/transactions/{account_id}/export.xlsx")
def export_gl_transactions_excel(
    account_id: uuid.UUID,
    date_from: date | None = None,
    date_to: date | None = None,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_module_access(MODULE, AccessLevel.VIEW)),
):
    rows, filename = _gl_transactions_for_export(
        db, current_user.company_id, account_id, date_from, date_to
    )
    data = exports.rows_to_excel(
        GL_TRANSACTION_EXPORT_FIELDS, rows, sheet_name="GL Transactions"
    )
    return StreamingResponse(
        iter([data]),
        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        headers={"Content-Disposition": f"attachment; filename={filename}.xlsx"},
    )
