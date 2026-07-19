<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Exception;

/**
 * Thrown when a Magento search request contains a construct Typesense's query
 * grammar cannot express (an unknown query/filter type, a wildcard filter, or a
 * negated compound). Surfaced instead of silently dropping the constraint, which
 * would return wrong results.
 */
class UnsupportedSearchRequestException extends \InvalidArgumentException
{
}
