<?php

declare(strict_types=1);

namespace Echo\Security;

final class TrustedProxy
{
    /** @param list<string> $trustedRanges */
    public function __construct(private readonly array $trustedRanges)
    {
    }

    public function isTrusted(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($this->trustedRanges as $range) {
            if ($this->matches($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $address, string $range): bool
    {
        if (!str_contains($range, '/')) {
            $addressBytes = @inet_pton($address);
            $rangeBytes = @inet_pton($range);
            return $addressBytes !== false
                && $rangeBytes !== false
                && strlen($addressBytes) === strlen($rangeBytes)
                && hash_equals($rangeBytes, $addressBytes);
        }

        [$network, $prefixRaw] = explode('/', $range, 2);
        if ($prefixRaw === '' || !ctype_digit($prefixRaw)) {
            return false;
        }

        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefixRaw;
        $maxBits = strlen($addressBytes) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
