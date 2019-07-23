<?php

/**
 * League.Uri (http://uri.thephpleague.com/components)
 *
 * @package    League\Uri
 * @subpackage League\Uri\Components
 * @author     Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @license    https://github.com/thephpleague/uri-components/blob/master/LICENSE (MIT License)
 * @version    2.0.0
 * @link       https://github.com/thephpleague/uri-components
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri;

use League\Uri\Maths\GMPMath;
use League\Uri\Maths\Math;
use League\Uri\Maths\PHPMath;
use function array_pop;
use function count;
use function decoct;
use function end;
use function explode;
use function extension_loaded;
use function ltrim;
use function octdec;
use function preg_match;
use function strpos;
use function substr;
use const PHP_INT_SIZE;

final class IPV4String
{
    private const MAX_IPV4_NUMBER = 4294967295;

    private static function math(): ?Math
    {
        static $math;
        if (null !== $math) {
            return $math;
        }

        if (8 === PHP_INT_SIZE) {
            $math = new PHPMath();

            return $math;
        }

        if (extension_loaded('gmp')) {
            $math = new GMPMath();

            return $math;
        }

        return null;
    }

    /**
     * Translates a IPv4 Host from a non decimal string representation.
     *
     * If the string can not be parsed it is returned as is.
     *
     * @param ?Math $math
     */
    public static function parse(string $host, ?Math $math = null): string
    {
        $math = $math ?? self::math();
        if (null === $math) {
            return $host;
        }

        if ('' === $host || 1 === preg_match('/\.\./', $host)) {
            return $host;
        }

        $parts = explode('.', $host);
        if ('' === end($parts)) {
            array_pop($parts);
        }

        if (4 < count($parts)) {
            return $host;
        }

        $numbers = [];
        foreach ($parts as $part) {
            $number = self::filterIPV4Part($part, $math);
            if (null === $number || $number < 0 || $number > self::MAX_IPV4_NUMBER) {
                return $host;
            }

            $numbers[] = $number;
        }

        $ipv4 = array_pop($numbers);
        $max = $math->pow(256, 6 - count($numbers));
        if ($math->compare($ipv4, $max) > 0) {
            return $host;
        }

        foreach ($numbers as $offset => $number) {
            if ($number > 255) {
                return $host;
            }
            $ipv4 += $math->multiply($number, $math->pow(256, 3 - $offset));
        }

        return $math->long2Ip($ipv4);
    }

    /**
     * Translates a IPv4 part from a non decimal representation to its integer representation.
     *
     * @return mixed Returns null if it can not correctly convert the part to the integer representation
     */
    private static function filterIPV4Part(string $part, Math $math)
    {
        if (0 === strpos($part, '0x')) {
            $part = ltrim(substr($part, 2), '0');
            if ('' === $part) {
                return 0;
            }

            if (!ctype_xdigit($part)) {
                return null;
            }

            return $math->baseConvert($part, 16);
        }

        if (0 === strpos($part, '0')) {
            $part = ltrim(substr($part, 1), '0');
            if ('' === $part) {
                return 0;
            }

            if ($part !== decoct(octdec($part))) {
                return null;
            }

            return $math->baseConvert($part, 8);
        }

        if (ctype_digit($part)) {
            return $math->baseConvert($part, 10);
        }

        return null;
    }
}
