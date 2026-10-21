<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Service\DecimalParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalParserTest extends TestCase
{
    private DecimalParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DecimalParser();
    }

    public function testNormalizesToScale(): void
    {
        $this->assertSame('10.00', $this->parser->parse('10', 16, 2));
        $this->assertSame('10.50', $this->parser->parse(' 10.5 ', 16, 2));
        $this->assertSame('0.01', $this->parser->parse('0.01', 16, 2));
    }

    public function testAcceptsJsonNumbers(): void
    {
        $this->assertSame('12.50', $this->parser->parse(12.5, 16, 2));
        $this->assertSame('7.000', $this->parser->parse(7, 15, 3));
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(mixed $raw): void
    {
        $this->assertNull($this->parser->parse($raw, 16, 2));
    }

    public static function invalidInputs(): array
    {
        return [
            'exponent'         => ['1e3'],
            'negative'         => ['-5'],
            'explicit plus'    => ['+5'],
            'too many decimals' => ['10.999'],
            'comma decimal'    => ['10,5'],
            'text'             => ['abc'],
            'empty'            => [''],
            'null'             => [null],
            'array'            => [[1]],
            'bool'             => [true],
            'overflow'         => ['99999999999999999999'],
            'trailing dot'     => ['10.'],
        ];
    }

    public function testIntegerDigitLimitMatchesColumn(): void
    {
        // numeric(18,2) -> 16 integer digits
        $this->assertSame('9999999999999999.99', $this->parser->parse('9999999999999999.99', 16, 2));
        $this->assertNull($this->parser->parse('10000000000000000', 16, 2));
    }
}
