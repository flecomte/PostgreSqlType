<?php
namespace FLE\Doctrine\DBAL\Types;

use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;

class DateTime extends DateTimeType
{
    public function convertToDatabaseValue ($value, AbstractPlatform $platform)
    {
        return ($value !== null) ? $value->format('Y-m-d H:i:s.u') : null;
    }

    public function convertToPHPValue ($value, AbstractPlatform $platform)
    {
        try {
            return parent::convertToPHPValue($value, $platform);
        } catch (ConversionException $e) {
            $val = \DateTime::createFromFormat('Y-m-d H:i:s.u', $value);
            if (! $val) {
                throw ConversionException::conversionFailedFormat($value, $this->getName(), 'Y-m-d H:i:s.u');
            }

            return $val;
        }
    }
}
