<?php

namespace Tests\Feature;

use App\Services\CredentialGenerator;
use Tests\TestCase;

class CredentialGeneratorTest extends TestCase
{
    private CredentialGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new CredentialGenerator();
    }

    public function test_parse_name_con_una_sola_palabra(): void
    {
        $this->assertSame(['Karen', 'Karen', []], $this->generator->parseName('Karen'));
    }

    public function test_parse_name_con_dos_palabras(): void
    {
        $this->assertSame(['Karen', 'Gonzalez', []], $this->generator->parseName('Karen Gonzalez'));
    }

    public function test_parse_name_con_tres_palabras(): void
    {
        $this->assertSame(['Juan', 'Perez', ['Gomez']], $this->generator->parseName('Juan Perez Gomez'));
    }

    public function test_parse_name_con_cuatro_palabras(): void
    {
        $this->assertSame(
            ['Roque', 'Esteche', ['Cantero', 'Luis']],
            $this->generator->parseName('Roque Luis Esteche Cantero')
        );
    }

    public function test_parse_name_con_cinco_o_mas_palabras(): void
    {
        $this->assertSame(
            ['Maria', 'Perez', ['Gomez']],
            $this->generator->parseName('Maria de los Angeles Perez Gomez')
        );
    }

    public function test_genera_credenciales_segun_el_esquema_estandar(): void
    {
        $creds = $this->generator->generateCredentials('Karen Gonzalez', '6868066', 'usil.edu.py');

        $this->assertSame('karen.gonzalez', $creds['login_id']);
        $this->assertSame('karen.gonzalez@usil.edu.py', $creds['email']);
        $this->assertSame('6868066-Kg', $creds['password']);
        $this->assertSame('Karen Gonzalez', $creds['full_name']);
        $this->assertSame('6868066', $creds['cedula']);
    }

    public function test_normaliza_tildes_y_mayusculas(): void
    {
        // Tres palabras -> (primera, segunda, [tercera]): nombre="María",
        // apellido="Ñandú", y "Pérez" queda como palabra extra.
        $creds = $this->generator->generateCredentials('María Ñandú Pérez', '1234', 'usil.edu.py');

        $this->assertSame('maria.nandu', $creds['login_id']);
        $this->assertSame('maria.nandu@usil.edu.py', $creds['email']);
        $this->assertSame('1234-Mn', $creds['password']);
    }

    public function test_aplica_el_sufijo_de_colision(): void
    {
        $creds = $this->generator->generateCredentials('Karen Gonzalez', '6868066', 'usil.edu.py', 'ro');

        $this->assertSame('karen.gonzalezro', $creds['login_id']);
        $this->assertSame('karen.gonzalezro@usil.edu.py', $creds['email']);
        // El sufijo de colisión nunca cambia la contraseña generada.
        $this->assertSame('6868066-Kg', $creds['password']);
    }

    public function test_nombre_vacio_usa_valores_por_defecto(): void
    {
        $creds = $this->generator->generateCredentials('', '0000', 'usil.edu.py');

        $this->assertSame('user.user', $creds['login_id']);
        $this->assertSame('0000-Uu', $creds['password']);
    }
}
