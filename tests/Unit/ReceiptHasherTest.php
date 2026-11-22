<?php

namespace Tests\Unit;

use App\Services\ReceiptHasher;
use PHPUnit\Framework\TestCase;

class ReceiptHasherTest extends TestCase
{
    private ReceiptHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new ReceiptHasher;
    }

    /**
     * Test Vector 1: Standard IDR 150.000,00
     */
    public function test_vector_1_matches_solidity_keccak256(): void
    {
        $hash = $this->hasher->compute(
            orderId: 'TRX-20261117-0001',
            amountMinor: '15000000',
            settlementTime: 1763380000,
            gatewayRef: 'MOCKGW-a1b2c3d4e5f60001'
        );

        $this->assertSame(
            '0x0f4a79aa0e14e8134c986a45b7d500a53beedde8cb8f3108b1ccccb02cd4efdb',
            $hash
        );
    }

    /**
     * Test Vector 2: Small amount IDR 10.500,00
     */
    public function test_vector_2_matches_solidity_keccak256(): void
    {
        $hash = $this->hasher->compute(
            orderId: 'TRX-20261117-0002',
            amountMinor: '1050000',
            settlementTime: 1763383600,
            gatewayRef: 'MOCKGW-b2c3d4e5f6a10002'
        );

        $this->assertSame(
            '0x466f96ee0cb8f627dd6a6542fb7d63dc14d291d69f746cebfb53cd7d009907f5',
            $hash
        );
    }

    /**
     * Test Vector 3: Large amount IDR 1.250.000,50
     */
    public function test_vector_3_matches_solidity_keccak256(): void
    {
        $hash = $this->hasher->compute(
            orderId: 'TRX-20261117-0003',
            amountMinor: '125000050',
            settlementTime: 1763387200,
            gatewayRef: 'MOCKGW-c3d4e5f6a1b20003'
        );

        $this->assertSame(
            '0xa9135fcf7860210ee667d940930295d29580241c77e75491a7cbf5bedeebf145',
            $hash
        );
    }
}
