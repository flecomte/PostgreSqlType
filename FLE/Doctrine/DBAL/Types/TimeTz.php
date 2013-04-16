<?php
namespace FLE\Doctrine\DBAL\Types;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;

class TimeTz extends Type
{
    const TIME_TZ = 'time_tz';

    public function getName()
    {
        return self::TIME_TZ;
    }

    public function getSQLDeclaration (array $fieldDeclaration, AbstractPlatform $platform)
    {
        return $platform->getDoctrineTypeMapping('TIME_TZ');
    }

    public function convertToDatabaseValue ($value, AbstractPlatform $platform)
    {
        return ($value !== null) ? $value->format('H:i:s.uP') : null;
    }

    public function convertToPHPValue ($value, AbstractPlatform $platform)
    {
        try {
            $val = \DateTime::createFromFormat('H:i:sP', $value);
        } catch (\Exception $e) {
            $val = \DateTime::createFromFormat('H:i:s.uP', $value);
            if (! $val) {
                throw ConversionException::conversionFailedFormat($value, $this->getName(), 'H:i:s.uP');
            }
        }

        return $val;
    }
}
