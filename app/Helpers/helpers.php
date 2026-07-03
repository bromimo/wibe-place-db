<?php

use Illuminate\Support\Facades\Hash;

if (!function_exists('make_hash')) {
    /** Создание хеша пароля.
     * @param string $password
     * @return string
     */
    function make_hash(string $password): string
    {
        return bcrypt($password);
    }
}

if (!function_exists('check_password')) {
    /**
     * IDE-helper для make_hash().
     */
    function check_password(string $plainTextPassword, string $hashPassword): bool
    {
        return Hash::check($plainTextPassword, $hashPassword);
    }
}

if (!function_exists('wrap')) {
    /** Оборачивает строку в заданные символы.
     * @param string      $data
     * @param string      $pattern
     * @param string|null $end_pattern
     * @return string
     */
    function wrap(string $data, string $pattern, string $end_pattern = null): string
    {
        $end_pattern = $end_pattern ?? $pattern;

        return "{$pattern}{$data}{$end_pattern}";
    }
}

if (!function_exists('dot_wrap')) {
    /** Оборачивает в заданный символ составляющие дот нотации.
     * @param string $string
     * @param string $quotes
     * @return string
     */
    function dot_wrap(string $string, string $quotes = '`'): string
    {
        $arr = explode('.', $string);
        $arr = array_map(static fn($item) => wrap($item, $quotes), $arr);

        return implode('.', $arr);
    }
}