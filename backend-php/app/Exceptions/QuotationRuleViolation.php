<?php

namespace App\Exceptions;

/**
 * A quotation status transition that the settled status model does not
 * allow (App\Services\QuotationService). Turned into a 409 by the
 * controller, the same way the other *RuleViolation exceptions become
 * 422s -- a 409 because the objection is to the record's current
 * state, not to the request's content.
 */
class QuotationRuleViolation extends \RuntimeException {}
