<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ColorRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Hexadecimal
        $hexRegex = '/^#[0-9A-Fa-f]{6}$/';
        
        // RGB
        $rgbRegex = '/^rgb\((\s*([0-9]{1,2}|1[0-9]{2}|2[0-4][0-9]|25[0-5])\s*,\s*){2}([0-9]{1,2}|1[0-9]{2}|2[0-4][0-9]|25[0-5])\s*\)$/';
        
        // Allowed named colors
        $namedColors = ['black', 'white', 'red', 'green', 'blue', 'yellow', 'cyan', 'magenta', 'gray', 'grey', 'orange', 'purple', 'pink', 'brown'];
        
        if (
            !preg_match($hexRegex, $value)
            && !preg_match($rgbRegex, $value)
            && !in_array($value, $namedColors)
        ) {
            $fail("The :attribute must be a valid color in hexadecimal, RGB or a recognized named color.");
        }
    }
}
