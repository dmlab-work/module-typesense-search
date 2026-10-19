<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Exception;

/**
 * Thrown when a Magento search request contains a construct Typesense's query
 * grammar cannot express (an unknown query/filter type, a negated wildcard or
 * compound). Surfaced instead of silently dropping the constraint, which
 * would return wrong results.
 */
class UnsupportedSearchRequestException extends \InvalidArgumentException
{
}
