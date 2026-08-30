<?php

namespace App\Exceptions;

use Exception;

class InspectionAssignmentException extends Exception
{
    public static function notAccreditor(): self
    {
        return new self('Only users with the Accreditor role can be assigned to an inspection.', 422);
    }

    public static function duplicate(): self
    {
        return new self('That accreditor is already assigned to this inspection.', 422);
    }

    public static function notBelongsToInspection(): self
    {
        return new self('That assignment does not belong to this inspection.', 422);
    }

    public static function dailyLimitExceeded(int $limit, int $count, string $date): self
    {
        return new self(
            "An accreditor may be assigned to at most {$limit} inspections per day"
                . " ({$count} already scheduled for {$date}).",
            422,
        );
    }

    public static function inspectionLimitExceeded(int $limit, int $count): self
    {
        return new self(
            "An inspection may have at most {$limit} accreditors (lead + members)"
                . " ({$count} already assigned).",
            422,
        );
    }
}
