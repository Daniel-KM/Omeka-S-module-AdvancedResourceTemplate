<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Stdlib;

/**
 * Common utility methods for AdvancedResourceTemplate.
 */
trait ArtTrait
{
    /**
     * Check if a value is true (true, 1, "1", "true", "yes", "on").
     *
     * This function avoids issues with values stored directly or with a form.
     * A value can be neither true nor false.
     */
    protected function valueIsTrue($value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /**
     * Check if a value is false (false, 0, "0", "false", "no", "off").
     *
     * This function avoids issues with values stored directly or with a form.
     * A value can be neither true nor false.
     */
    protected function valueIsFalse($value): bool
    {
        return in_array($value, [false, 0, '0', 'false', 'no', 'off'], true);
    }
}
