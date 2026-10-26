<?php

namespace OmopCdm\OmopCdmExportModule;

/**
 * Pseudonymization of what goes out to the CDM.
 *
 * Hashing person_source_value removes the Record ID, but full dates still
 * identify people: date of birth + admission date + postcode are enough to
 * re-identify many of them. OHDSI networks usually also ask for:
 *
 *  - Date shifting: all of a person's dates move by the same number of days,
 *    different for each person. Intervals (age, time to an event, duration of
 *    a treatment) are preserved; the real dates are not.
 *  - Reduced birth precision: year, or year and month.
 *  - No free text: a notes field or a hand-written cause of death may contain
 *    names, addresses or anything else.
 *
 * Each person's shift is derived from a secret project salt and the Record
 * ID, so it is the same on every export.
 */
class Pseudonymizer
{
    private $shiftDays;
    private $birthPrecision;
    private $suppressFreeText;
    private $salt;
    private $offsets = [];

    public function __construct(int $shiftDays, string $birthPrecision, bool $suppressFreeText, string $salt)
    {
        $this->shiftDays        = max(0, $shiftDays);
        $this->birthPrecision   = in_array($birthPrecision, ['full', 'month', 'year'], true) ? $birthPrecision : 'full';
        $this->suppressFreeText = $suppressFreeText;
        $this->salt             = $salt;
    }

    public function shiftsDates(): bool
    {
        return $this->shiftDays > 0;
    }

    public function suppressesFreeText(): bool
    {
        return $this->suppressFreeText;
    }

    public function birthPrecision(): string
    {
        return $this->birthPrecision;
    }

    /** Number of days a record's dates are shifted, between -N and +N. */
    public function offset(string $recordId): int
    {
        if ($this->shiftDays === 0) {
            return 0;
        }
        if (!isset($this->offsets[$recordId])) {
            $h = hexdec(substr(hash('sha256', $this->salt . '|shift|' . $recordId), 0, 8));
            $off = ($h % (2 * $this->shiftDays + 1)) - $this->shiftDays;
            // A shift of zero would leave that person's real dates.
            $this->offsets[$recordId] = $off === 0 ? $this->shiftDays : $off;
        }
        return $this->offsets[$recordId];
    }

    /**
     * Y-m-d date shifted for the record.
     *
     * An impossible date ("2024-13-01", "2024-02-30") or a text that is not a
     * date returns null: DateTime would "normalise" it to another day, and
     * shifting a made-up day would be making up data.
     */
    public function shift(?string $date, string $recordId): ?string
    {
        if ($date === null || $this->shiftDays === 0) {
            return $date;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            return null;
        }
        $d->modify(($this->offset($recordId) >= 0 ? '+' : '') . $this->offset($recordId) . ' days');
        return $d->format('Y-m-d');
    }

    /**
     * Applies the birth precision to a PERSON row that has already been shifted.
     */
    public function birth(array $row): array
    {
        if ($this->birthPrecision === 'full') {
            return $row;
        }
        $row['day_of_birth']   = null;
        $row['birth_datetime'] = null;
        if ($this->birthPrecision === 'year') {
            $row['month_of_birth'] = null;
        }
        return $row;
    }
}
