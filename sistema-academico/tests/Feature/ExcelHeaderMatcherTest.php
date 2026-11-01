<?php

namespace Tests\Feature;

use App\Services\Excel\ExcelHeaderMatcher;
use Tests\TestCase;

class ExcelHeaderMatcherTest extends TestCase
{
    private ExcelHeaderMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new ExcelHeaderMatcher();
    }

    public function test_normaliza_tildes_mayusculas_y_espacios(): void
    {
        $this->assertSame('cedula_ci', $this->matcher->normalize('Cédula  (CI)'));
        $this->assertSame('nombre_completo', $this->matcher->normalize('Nombre Completo *'));
        $this->assertSame('email', $this->matcher->normalize('  EMAIL  '));
    }

    public function test_prioriza_coincidencia_exacta_sobre_coincidencia_parcial(): void
    {
        // Caso real de producción: "ci" es abreviatura de Cédula/CI, pero
        // también aparece como substring de "Servicio" y "Inscripción". Sin
        // priorizar la coincidencia exacta, una de esas columnas le gana a
        // la columna real de CI y corrompe la contraseña generada
        // (cedula-Iniciales termina siendo texto_de_otra_columna-Iniciales).
        $headers = [
            $this->matcher->normalize('Fecha de Inscripción') => 0,
            $this->matcher->normalize('Servicio') => 1,
            $this->matcher->normalize('CI') => 2,
            $this->matcher->normalize('Nombre') => 3,
        ];

        $indice = $this->matcher->matchColumn($headers, 'cedula', 'ci', 'documento');

        $this->assertSame(2, $indice, 'Debe encontrar la columna exacta "CI", no las que la contienen como substring.');
    }

    public function test_cae_a_coincidencia_parcial_si_no_hay_coincidencia_exacta(): void
    {
        $headers = [
            $this->matcher->normalize('Nombre Completo del Alumno') => 0,
            $this->matcher->normalize('Correo Electrónico Personal') => 1,
        ];

        $indice = $this->matcher->matchColumn($headers, 'nombre');

        $this->assertSame(0, $indice);
    }

    public function test_devuelve_null_si_ninguna_key_coincide(): void
    {
        $headers = [$this->matcher->normalize('Teléfono') => 0];

        $this->assertNull($this->matcher->matchColumn($headers, 'cedula', 'ci'));
    }

    public function test_match_header_name_devuelve_el_nombre_del_encabezado_no_el_indice(): void
    {
        $headers = [
            $this->matcher->normalize('Servicio') => 0,
            $this->matcher->normalize('Nombre Completo') => 1,
            $this->matcher->normalize('CI') => 2,
        ];

        $this->assertSame('ci', $this->matcher->matchHeaderName($headers, 'cedula', 'ci', 'documento'));
        $this->assertSame('nombre_completo', $this->matcher->matchHeaderName($headers, 'nombre_completo', 'nombre'));
    }

    public function test_match_header_name_resuelve_por_substring_un_encabezado_que_ninguna_key_matchea_exacto(): void
    {
        // Antes de resolver el encabezado una sola vez (en vez de buscar las
        // keys candidatas como claves literales de cada fila), este caso era
        // inalcanzable: "Nro. de Documento" no coincide exacto con ninguna
        // de las keys, solo las contiene como substring.
        $headers = ['nro_de_documento' => 0];

        $this->assertSame('nro_de_documento', $this->matcher->matchHeaderName($headers, 'cedula', 'ci', 'documento'));
    }

    public function test_match_header_name_devuelve_null_si_no_hay_coincidencia(): void
    {
        $headers = [$this->matcher->normalize('Teléfono') => 0];

        $this->assertNull($this->matcher->matchHeaderName($headers, 'cedula', 'ci'));
    }

    public function test_primer_no_vacio_prueba_variantes_espanol_e_ingles(): void
    {
        $row = ['nombre_completo' => '  Karen Gonzalez  ', 'other' => ''];

        $this->assertSame('Karen Gonzalez', $this->matcher->firstNonEmpty($row, 'full_name', 'nombre_completo', 'nombre'));
    }

    public function test_primer_no_vacio_devuelve_null_si_todo_esta_vacio_o_ausente(): void
    {
        $row = ['nombre' => '   ', 'otro' => null];

        $this->assertNull($this->matcher->firstNonEmpty($row, 'nombre', 'full_name'));
    }
}
