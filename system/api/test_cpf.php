<?php
function validateCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $p = 0; $p < $t; $p++) {
            $d += $cpf[$p] * (($t + 1) - $p);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$t] != $d) return false;
    }
    return true;
}

$testCPFs = [
    '123.456.789-10',
    '987.654.321-09',
    '111.222.333-44',
    '111.111.111-11',
    '555.123.456-78',
    '444.987.321-65'
];

foreach ($testCPFs as $cpf) {
    $result = validateCPF($cpf) ? '✓ Valid' : '✗ Invalid';
    echo "$cpf: $result\n";
}

// Gerar um CPF válido (demo)
echo "\nGerando CPFs válidos para teste:\n";
for ($i = 0; $i < 3; $i++) {
    $base = str_pad(rand(1, 999999999), 9, '0', STR_PAD_LEFT);
    $d1 = 0;
    for ($p = 0; $p < 9; $p++) {
        $d1 += $base[$p] * (10 - $p);
    }
    $d1 = ((10 * $d1) % 11) % 10;
    
    $d2 = 0;
    $temp = $base . $d1;
    for ($p = 0; $p < 10; $p++) {
        $d2 += $temp[$p] * (11 - $p);
    }
    $d2 = ((10 * $d2) % 11) % 10;
    
    $cpf_full = substr($base, 0, 3) . '.' . substr($base, 3, 3) . '.' . substr($base, 6, 3) . '-' . $d1 . $d2;
    echo "$cpf_full (Valid: " . (validateCPF($cpf_full) ? 'Yes' : 'No') . ")\n";
}
?>
