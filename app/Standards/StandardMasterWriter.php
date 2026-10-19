<?php

namespace App\Standards;

use App\Models\InstrumentTypeStandardAssignment;
use App\Models\StandardMaster;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StandardMasterWriter
{
    public const NAME_MAX = 150;

    public const TYPE_MAX = 150;

    public const SERIAL_MAX = 150;

    public const CODE_MAX = 20;

    public function create(string $instrumentName, ?string $typeSpecification = null, ?string $serialNumber = null, ?string $standardCode = null): StandardMaster
    {
        try {
            return DB::transaction(function () use ($instrumentName, $typeSpecification, $serialNumber, $standardCode): StandardMaster {
                $standard = new StandardMaster;
                $standard->standard_code = $this->uniqueCode($standardCode);
                $standard->instrument_name = $this->requiredText($instrumentName, self::NAME_MAX, 'instrumentName');
                $standard->type_specification = $this->nullableText($typeSpecification, self::TYPE_MAX, 'typeSpecification');
                $standard->serial_number = $this->nullableText($serialNumber, self::SERIAL_MAX, 'serialNumber');
                $standard->save();

                return $standard;
            });
        } catch (StandardMasterException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new StandardMasterException($this->constraintMessage($exception->getMessage()));
        }
    }

    public function update(int $standardId, string $instrumentName, ?string $typeSpecification = null, ?string $serialNumber = null): StandardMaster
    {
        try {
            return DB::transaction(function () use ($standardId, $instrumentName, $typeSpecification, $serialNumber): StandardMaster {
                $standard = StandardMaster::query()
                    ->where('standard_id', $standardId)
                    ->lockForUpdate()
                    ->first();

                if ($standard === null) {
                    throw new StandardMasterException('This standard could not be found.');
                }

                $standard->instrument_name = $this->requiredText($instrumentName, self::NAME_MAX, 'instrumentName');
                $standard->type_specification = $this->nullableText($typeSpecification, self::TYPE_MAX, 'typeSpecification');
                $standard->serial_number = $this->nullableText($serialNumber, self::SERIAL_MAX, 'serialNumber');
                $standard->save();

                return $standard;
            });
        } catch (StandardMasterException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new StandardMasterException($this->constraintMessage($exception->getMessage()));
        }
    }

    public function delete(int $standardId): void
    {
        try {
            DB::transaction(function () use ($standardId): void {
                $standard = StandardMaster::query()
                    ->where('standard_id', $standardId)
                    ->lockForUpdate()
                    ->first();

                if ($standard === null) {
                    throw new StandardMasterException('This standard could not be found.');
                }

                if (InstrumentTypeStandardAssignment::query()->where('standard_id', $standardId)->exists()) {
                    throw new StandardMasterException('This standard is assigned to an instrument type and cannot be deleted.');
                }

                $standard->delete();
            });
        } catch (StandardMasterException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new StandardMasterException($this->constraintMessage($exception->getMessage()));
        }
    }

    private function uniqueCode(?string $standardCode): string
    {
        $code = trim((string) $standardCode);

        if ($code === '') {
            return $this->nextCode();
        }

        if (mb_strlen($code) > self::CODE_MAX) {
            throw new StandardMasterException('The standard ID may not be greater than '.self::CODE_MAX.' characters.', 'standardCode');
        }

        if (StandardMaster::query()->where('standard_code', $code)->exists()) {
            throw new StandardMasterException('This standard ID is already in use.', 'standardCode');
        }

        return $code;
    }

    private function nextCode(): string
    {
        $codes = StandardMaster::query()->lockForUpdate()->pluck('standard_code');
        $max = 0;

        foreach ($codes as $code) {
            if (preg_match('/^STD-(\d+)$/', (string) $code, $matches) !== 1) {
                continue;
            }

            $max = max($max, (int) $matches[1]);
        }

        return 'STD-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function requiredText(string $value, int $max, string $field): string
    {
        $text = $this->nullableText($value, $max, $field);

        if ($text === null) {
            throw new StandardMasterException('Instrument Name is required.', $field);
        }

        return $text;
    }

    private function nullableText(?string $value, int $max, string $field): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw new StandardMasterException(
                'The '.$this->fieldLabel($field).' may not be greater than '.$max.' characters.',
                $field,
            );
        }

        return $text;
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'instrumentName' => 'instrument name',
            'typeSpecification' => 'type / specification',
            'serialNumber' => 'serial number',
            'standardCode' => 'standard ID',
            default => $field,
        };
    }

    private function constraintMessage(string $message): string
    {
        if (str_contains(mb_strtolower($message), 'uq_standard_master_code') || str_contains(mb_strtolower($message), 'standard_master.standard_code')) {
            return 'This standard ID is already in use.';
        }

        if (str_contains(mb_strtolower($message), 'fk_instrument_type_standard_assignment_master')) {
            return 'This standard is assigned to an instrument type and cannot be deleted.';
        }

        return 'The standard could not be saved. Refresh the page and try again.';
    }
}
