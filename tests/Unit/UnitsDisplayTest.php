<?php

namespace Tests\Unit;

use App\Support\Units;
use PHPUnit\Framework\TestCase;

class UnitsDisplayTest extends TestCase
{
    public function test_display_keeps_thousands_and_trims_decimals(): void
    {
        $this->assertSame('5,000 units', Units::display(5000, 'ea'));
        $this->assertSame('1 unit', Units::display(1, 'ea'));
        $this->assertSame('100 units', Units::display(100, 'ea'));
        $this->assertSame('2.5 kg', Units::display(2500, 'g'));
        $this->assertSame('1 kg', Units::display(1000, 'g'));
        $this->assertSame('50 g', Units::display(50, 'g'));
        $this->assertSame('0.5 g', Units::display(0.5, 'g'));
        $this->assertSame('0 g', Units::display(0, 'g'));
        $this->assertSame('120 ml', Units::display(120, 'ml'));
    }
}
