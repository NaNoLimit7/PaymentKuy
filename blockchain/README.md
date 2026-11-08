# ⛓️ PaymentKuy — Smart Contract

**PaymentReceiptRegistry** — Immutable on-chain audit trail for e-commerce payment receipts on Ethereum Sepolia Testnet.

> Bagian dari Tugas Akhir Teknologi Blockchain, FILKOM Universitas Brawijaya.

---

## 📁 Structure

```
blockchain/
├── contracts/
│   └── PaymentReceiptRegistry.sol   ← Main smart contract
├── scripts/
│   └── deploy.js                    ← Deployment script
├── test/
│   └── PaymentReceiptRegistry.test.js ← 24 test cases
├── deployments/                     ← Auto-generated after deploy
│   ├── localhost.json
│   └── sepolia.json
├── hardhat.config.js
├── .env.example                     ← Copy → .env for Sepolia
└── package.json
```

---

## 🚀 Quick Start

### 1. Install dependencies
```bash
cd blockchain
npm install
```

### 2. Run tests (no config needed — uses local Hardhat node)
```bash
npm test
# or verbose:
npm run test:verbose
```

### 3. Compile contracts
```bash
npm run compile
```

---

## 🔐 Deploy to Sepolia Testnet

### Prerequisites
1. **MetaMask wallet** with Sepolia ETH
   - Get free testnet ETH: https://sepoliafaucet.com
2. **Alchemy / Infura account** (free tier) for RPC URL
3. **Etherscan API key** (free) for contract verification

### Setup `.env`
```bash
cp .env.example .env
```

Edit `.env`:
```env
DEPLOYER_PRIVATE_KEY=0xYOUR_METAMASK_PRIVATE_KEY
SEPOLIA_RPC_URL=https://eth-sepolia.g.alchemy.com/v2/YOUR_API_KEY
ETHERSCAN_API_KEY=YOUR_ETHERSCAN_API_KEY
```

> ⚠️ **NEVER commit `.env` to git!**

### Deploy
```bash
npm run deploy:sepolia
```

### Verify on Etherscan (optional but recommended)
```bash
npx hardhat verify --network sepolia <CONTRACT_ADDRESS> <YOUR_WALLET_ADDRESS>
```

---

## 📋 Contract API

### `recordReceipt(orderId, receiptHash, amount, settlementTime, gatewayRef, currency)`
Records a settled payment receipt on-chain. Only callable by the owner or authorized gateways.

| Param | Type | Description |
|-------|------|-------------|
| `orderId` | `string` | Unique order ID from e-commerce platform |
| `receiptHash` | `bytes32` | `keccak256(orderId + amount + settlementTime + gatewayRef)` |
| `amount` | `uint256` | Amount in smallest unit (e.g., IDR cents) |
| `settlementTime` | `uint256` | Unix timestamp of settlement |
| `gatewayRef` | `string` | Gateway reference (Midtrans/Xendit transaction ID) |
| `currency` | `string` | ISO 4217 code (e.g., `"IDR"`) |

### `verifyReceipt(orderId, amount, settlementTime, gatewayRef) → (bool, bytes32)`
Public function — anyone can verify a receipt without authorization.

### `getReceipt(orderId) → Receipt`
Returns the full receipt struct.

### `hasReceipt(orderId) → bool`
Returns `true` if an order has a recorded receipt.

### `computeReceiptHash(orderId, amount, settlementTime, gatewayRef) → bytes32`
Pure helper — computes the hash the same way as the contract.

---

## 🧪 Test Coverage (24 tests)

| Scenario | Tests |
|----------|-------|
| ✅ Deployment | 3 |
| ✅ Scenario 1 — Successful Settlement | 5 |
| ❌ Scenario 2 — Failed/Denied Payment | 5 |
| ⏰ Scenario 3 — Expired Session | 2 |
| 🔍 Public Audit — verifyReceipt | 2 |
| 🔐 Access Control | 5 |
| 🧪 Edge Cases | 2 |

---

## 🔗 How the Backend Integrates (Laravel)

```php
// 1. When webhook settlement arrives, compute hash off-chain:
$hash = keccak256(abi.encodePacked($orderId, $amount, $settlementTime, $gatewayRef));

// 2. Call recordReceipt() via web3.php or ethers.js script

// 3. Store tx_hash in your DB for the audit portal URL:
//    https://sepolia.etherscan.io/tx/{tx_hash}
```

---

## 📊 Events

| Event | When emitted |
|-------|-------------|
| `ReceiptRecorded(orderId, receiptHash, amount, currency, settlementTime, recordedBy)` | On successful `recordReceipt()` |
| `StatusUpdated(orderId, oldStatus, newStatus, updatedBy)` | On `updateStatus()` |
| `GatewayAuthorizationChanged(gateway, authorized)` | On `setGatewayAuthorization()` |
