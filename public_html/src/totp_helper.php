<?php
/**
 * TOTP (Time-based One-Time Password) Helper
 * Simple implementation without external dependencies
 */

class SimpleTOTP {

    /**
     * Generate a random base32 secret
     */
    public static function generateSecret($length = 16) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Decode base32 string
     */
    private static function base32Decode($secret) {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));

        $secret = strtoupper($secret);
        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = [6, 4, 3, 1, 0];

        if (!in_array($paddingCharCount, $allowedValues)) {
            return false;
        }

        for ($i = 0; $i < 4; $i++) {
            if ($paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])) {
                return false;
            }
        }

        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';

        for ($i = 0; $i < count($secret); $i = $i + 8) {
            $x = '';
            if (!in_array($secret[$i], $base32charsFlipped)) {
                return false;
            }
            for ($j = 0; $j < 8; $j++) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= (($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : '';
            }
        }

        return $binaryString;
    }

    /**
     * Get current OTP code
     */
    public static function getCode($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretkey = self::base32Decode($secret);

        // Pack time into binary string
        $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);

        // Hash it with SHA1
        $hm = hash_hmac('SHA1', $time, $secretkey, true);

        // Use last nibble of result as index/offset
        $offset = ord(substr($hm, -1)) & 0x0F;

        // Grab 4 bytes of the result
        $hashpart = substr($hm, $offset, 4);

        // Unpack binary value
        $value = unpack('N', $hashpart);
        $value = $value[1];

        // Only 32 bits
        $value = $value & 0x7FFFFFFF;

        $modulo = pow(10, 6);

        return str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify OTP code
     */
    public static function verify($secret, $code, $discrepancy = 1) {
        $currentTimeSlice = floor(time() / 30);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if ($calculatedCode == $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get QR Code URL for setup
     */
    public static function getQRCodeUrl($secret, $issuer, $accountName) {
        $otpauthUrl = 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountName) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauthUrl);
    }
}
