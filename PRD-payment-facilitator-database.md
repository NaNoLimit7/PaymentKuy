# PRD: Prototipe Payment Facilitator E-Commerce (Fokus Database)

> Dokumen ini ditujukan sebagai konteks untuk AI coding agent (opencode CLI).
> Referensikan dengan: `@PRD-payment-facilitator-database.md`
> Versi: 1.5 (revisi: database sudah dibuat di Laragon dan masih kosong; migration hanya membuat tabel)

---

## 1. Ringkasan

Prototipe **payment facilitator** untuk e-commerce dengan tiga pilar:

1. **Tokenisasi data**: data sensitif pembayaran (mock) tidak disimpan mentah; yang beredar di sistem hanyalah token.
2. **Idempotency**: request pembayaran yang sama tidak boleh menghasilkan transaksi ganda.
3. **Audit trail berbasis blockchain**: setiap event transaksi dicatat di tabel audit berantai-hash (off-chain), dan *receipt* pembayaran yang sukses dicatat on-chain ke **Ethereum Sepolia testnet** lewat smart contract `PaymentReceiptRegistry.sol`.

Seluruh data bersifat **mock**. Tidak ada integrasi payment gateway/bank sungguhan.

**Lingkup tugas ini: perancangan dan implementasi DATABASE** (skema, migration, seeder, constraint, query kritis) beserta service Laravel yang langsung menyentuh database. Sisi blockchain (smart contract + pengiriman ke Sepolia) dikerjakan orang lain; PRD ini hanya mendefinisikan **batas integrasinya** (Bagian 4.4 dan 6.4).

## 2. Tech Stack

| Komponen | Pilihan |
|---|---|
| Backend | Laravel (RESTful API) |
| Database | MariaDB (via Laragon) |
| Charset/Collation | `utf8mb4` / `utf8mb4_unicode_ci` |
| Engine | InnoDB (wajib, untuk transaksi & row lock) |
| Blockchain | Ethereum Sepolia testnet, smart contract `PaymentReceiptRegistry.sol` (dikerjakan rekan) |
| Auth API (prototipe) | API key per merchant (disimpan sebagai hash) |

### Kondisi Awal Database
- Database **sudah dibuat** di Laragon (MariaDB) dan **belum memiliki tabel sama sekali**.
- Karena itu migration **hanya membuat tabel**; jangan menjalankan `CREATE DATABASE` dari migration atau kode aplikasi.
- Nama database mengikuti yang sudah ada dan diisi di `DB_DATABASE` pada `.env` (nama `pf_prototype` pada dokumen ini hanya contoh).
- Pastikan charset database `utf8mb4` / `utf8mb4_unicode_ci`. Jika belum, jalankan (ganti nama sesuai database):
  `ALTER DATABASE nama_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
- Migration bawaan Laravel (users, cache, jobs, dsb.) boleh dibiarkan; tabel `users` tidak dipakai karena autentikasi memakai API key merchant, sehingga migration `users` boleh dihapus.
- `migrate:fresh` menghapus **semua** tabel di database tersebut. Aman selama database ini dipakai khusus untuk proyek ini; jika dipakai bersama pihak lain, gunakan `migrate` biasa.

## 3. Tujuan & Non-Tujuan

### Tujuan
- Skema database yang menjamin integritas data transaksi.
- Idempotency checker yang aman terhadap race condition (request paralel dengan key sama).
- State transaksi: `pending` → `proceed`, atau `pending` → `expired`.
- Satu order dapat memiliki banyak transaksi (percobaan bayar ulang, dsb.) **tanpa batas transaksi aktif** (lihat 4.5).
- Audit trail tamper-evident (hash chain + receipt on-chain).
- Migration + seeder mock data yang siap dijalankan di Laragon.

### Non-Tujuan
- Pengembangan smart contract, manajemen wallet/private key, dan kode pengiriman transaksi ke Sepolia (dikerjakan rekan).
- Integrasi payment gateway nyata, PCI-DSS penuh, settlement/payout, refund, multi-currency.

## 4. Aturan Bisnis Inti

### 4.1 State Transaksi

```
            (pembayaran diterima sebelum expires_at)
pending ─────────────────────────────────────────────▶ proceed   (final)
   │
   └──(melewati expires_at tanpa pembayaran)─────────▶ expired   (final)
```

- Transaksi baru **selalu** dibuat `pending` dengan `expires_at = now + TRANSACTION_EXPIRY_MINUTES` (default 60 menit, dari `.env`).
- `proceed` dan `expired` bersifat **final**: tidak boleh berubah lagi (ditegakkan trigger).
- Method pada model/service:
  - `Transaction::pending(...)`: membuat transaksi berstatus pending.
  - `$transaction->proceed(PaymentToken $t)`: transisi `pending → proceed`, mengisi `paid_at` dan `payment_token_id`.
  - `$transaction->expire()`: transisi `pending → expired`, mengisi `expired_at`.
- Setiap transisi dibungkus **satu DB transaction** dan wajib menulis `transaction_state_histories` + `audit_logs` (+ baris outbox receipt untuk `proceed`, lihat 4.4).
- **Expiry ada dua jalur**:
  1. *Aktif (scheduled)*: command `transactions:expire` berjalan tiap menit, memproses `status='pending' AND expires_at <= NOW()` per batch (chunk), memanggil `expire()` per baris.
  2. *Lazy*: saat `proceed()` dipanggil, baris dikunci (`lockForUpdate`) lalu `expires_at` dicek. Jika sudah lewat, transaksi di-`expire()` dan request ditolak `422` (`transaction_expired`). Ini menjamin transaksi yang lewat waktu tidak bisa dibayar walaupun scheduler belum jalan.
- Mencoba `proceed()` pada transaksi `proceed` (key idempotency baru) → no-op/tolak `409`, **tanpa** efek samping apa pun. Pada transaksi `expired` → `422`.

### 4.2 Idempotency
- Client mengirim header `Idempotency-Key` (string, maks 128 karakter, disarankan UUID v4) pada endpoint yang mengubah state (buat transaksi, bayar).
- Kunci unik: **(merchant_id, idempotency_key, endpoint)**.
- Alur `idempotency_checker`:
  1. Hitung `request_hash` = SHA-256 dari body request yang dinormalisasi (key diurutkan).
  2. `INSERT` ke `idempotency_keys` dengan status `processing`.
  3. **Insert berhasil** → jalankan proses bisnis, simpan `response_code` + `response_body`, set `completed`.
  4. **Duplicate key**:
     - `request_hash` berbeda → `422` (key dipakai ulang dengan payload lain).
     - status `completed` → kembalikan **response tersimpan** (code & body sama), header `Idempotent-Replayed: true`.
     - status `processing` → `409` (sedang diproses).
- Response error bisnis yang deterministik (mis. `422 transaction_expired`) **ikut disimpan** dan diputar ulang untuk key yang sama. Error teknis `5xx` **tidak** disimpan; key dilepas agar client bisa retry.
- Key kedaluwarsa setelah 24 jam (`expires_at`); dibersihkan scheduled command `idempotency:purge`.
- Race condition ditangani oleh **UNIQUE constraint** + `SELECT ... FOR UPDATE` di dalam DB transaction, bukan sekadar cek di aplikasi.
- Jika proses crash saat `processing` melebihi `locked_at + IDEMPOTENCY_STALE_SECONDS`, key dianggap *stale* dan boleh diambil alih.

### 4.3 Tokenisasi
- Data kartu/rekening mock **tidak pernah** disimpan mentah. **Tidak ada kolom CVV**.
- Yang disimpan: `token` (acak), `last4`, `brand`, `fingerprint` (HMAC-SHA256 nomor, untuk deteksi duplikat), dan `encrypted_payload` (AES-256 via `Crypt` Laravel).
- Tabel transaksi hanya merujuk `payment_token_id`.
- Format token: `tok_` + 32 karakter acak.
- Jenis metode mock: `card`, `bank_account`, `ewallet`.

### 4.4 Audit Trail & Integrasi Blockchain

Dua lapis:

**Lapis 1: hash chain off-chain (`audit_logs`)**, ditulis oleh Laravel.
- Setiap record menyimpan `prev_hash` dan `record_hash = SHA256(prev_hash + event_type + payload_hash + created_at)`.
- Tabel **sepenuhnya append-only** (UPDATE dan DELETE ditolak trigger).
- Event minimal: `transaction.created`, `transaction.proceed`, `transaction.expired`.
- Payload **tidak boleh** berisi data sensitif; hanya ID, status, amount, currency, dan hash.

**Lapis 2: receipt on-chain (`blockchain_receipts`)**, memakai smart contract `PaymentReceiptRegistry.sol` (Solidity ^0.8.28, OpenZeppelin `Ownable` + `ReentrancyGuard`) di Sepolia.

#### Fakta dari kode kontrak yang memengaruhi desain database

| Fakta di kontrak | Konsekuensi untuk sistem ini |
|---|---|
| `recordReceipt(orderId, receiptHash, amount, settlementTime, gatewayRef, currency)` hanya bisa dipanggil **owner** atau alamat yang didaftarkan lewat `setGatewayAuthorization` | Wallet penandatangan harus didaftarkan oleh owner kontrak (urusan rekan) |
| `orderId` (string) adalah **kunci unik**; pemanggilan kedua dengan `orderId` sama **revert** ("order ID already recorded") | Yang dikirim sebagai `orderId` adalah **`transactions.reference`**, BUKAN nomor/uuid order. Karena satu order bisa punya banyak transaksi, memakai ID order akan bentrok |
| `recordReceipt` selalu menyimpan status `SETTLED`. `updateStatus` hanya berlaku untuk receipt yang **sudah ada** dan **menolak perubahan dari SETTLED** | Receipt tidak bisa dibuat untuk transaksi `pending`/`expired`. Jadi **hanya transaksi `proceed` yang masuk blockchain**; `expired` hanya tercatat off-chain (`audit_logs`). Fungsi `updateStatus` tidak dipakai |
| `receiptHash = keccak256(abi.encodePacked(orderId, amount, settlementTime, gatewayRef))` | Memakai **Keccak-256 (Ethereum)**, bukan SHA-256. Hash ini terpisah dari hash audit chain |
| `amount` bertipe `uint256` dalam satuan terkecil (komentar kontrak: 150000 untuk Rp 1.500,00, yaitu x100) | Kolom `amount_minor` = `amount x 100` (generated column) |
| `settlementTime` = unix timestamp (detik), harus > 0; `gatewayRef` dan `currency` tidak boleh kosong | `paid_at` dikonversi ke unix detik; `gateway_ref` **wajib terisi** untuk `proceed` |

#### Pemetaan field ke fungsi `recordReceipt`

| Parameter kontrak | Sumber di database |
|---|---|
| `orderId` | `transactions.reference` (disalin ke `blockchain_receipts.contract_order_id`) |
| `receiptHash` | dihitung Laravel (lihat 6.4) → `blockchain_receipts.receipt_hash` |
| `amount` | `transactions.amount_minor` |
| `settlementTime` | `UNIX_TIMESTAMP(transactions.paid_at)` |
| `gatewayRef` | `transactions.gateway_ref` |
| `currency` | `transactions.currency` |

#### Alur (pola outbox)
1. Saat `proceed()` berjalan, `gateway_ref` diisi (mock: `MOCKGW-` + 16 hex acak; pada sistem nyata berasal dari webhook gateway, mis. Midtrans seperti disebut di komentar kontrak).
2. Dalam **DB transaction yang sama**: hitung `receipt_hash`, tulis audit log `transaction.proceed` (payload memuat `receipt_hash`, sehingga hash chain off-chain "mengikat" receipt on-chain), lalu buat baris `blockchain_receipts` berstatus `pending`.
3. Pengiriman ke Sepolia **tidak** dilakukan di dalam request HTTP maupun DB transaction. Worker (`blockchain:submit`) mengambil receipt `pending`, memanggil `BlockchainGateway`, lalu menyimpan `tx_hash`, `block_number`, dan status.
4. **Pengiriman harus idempoten**: sebelum mengirim, cek `hasReceipt(contract_order_id)`. Jika kontrak revert "already recorded", verifikasi dengan `verifyReceipt(...)` lalu tandai `confirmed` (bukan `failed`) agar retry tidak mencatat ganda.
5. Gagal → `attempts++`, `last_error`, `next_attempt_at` (backoff), maksimal `BLOCKCHAIN_MAX_ATTEMPTS`, lalu `failed`.
6. Kegagalan blockchain **tidak boleh** membatalkan atau memundurkan status `proceed`.
7. Private key wallet dikelola sisi pengirim (rekan), bukan disimpan di database.

### 4.5 Relasi Order : Transaksi (1 : N)
- Satu order boleh memiliki **banyak transaksi** tanpa batas jumlah transaksi aktif (`pending`, `proceed`, `expired` boleh bercampur).
- Setiap transaksi independen: `reference` sendiri, `expires_at` sendiri, idempotency key sendiri, dan (bila `proceed`) receipt on-chain sendiri.
- `amount` transaksi default-nya `orders.amount` dan divalidasi sama dengan `orders.amount` di service.
- **Konsekuensi yang perlu disadari**: database tidak mencegah satu order memiliki lebih dari satu transaksi `proceed` (pembayaran ganda). Jika kelak diperlukan, pencegahan ditambahkan di level service, tanpa mengubah skema.

## 5. Skema Database

### 5.1 Relasi (ERD)

```
merchants 1───* orders *───1 customers
    │              │
    │              └───1:N───▶ transactions *───0..1 payment_tokens
    │                               │ 1
    ├──* idempotency_keys ──────────┤ (transaction_id, nullable)
    │                               ├──* transaction_state_histories
    │                               ├──* audit_logs (hash chain)
    │                               └──0..1 blockchain_receipts ──▶ audit_logs
    └───────────────────────────────┘
```

### 5.2 DDL MariaDB (acuan)

```sql
-- Database SUDAH ADA di Laragon (kosong). Skrip ini hanya membuat tabel.
-- Jalankan di dalam database tersebut (USE nama_database;), tanpa CREATE DATABASE.

-- 1. MERCHANTS
CREATE TABLE merchants (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36)     NOT NULL UNIQUE,
  name          VARCHAR(150) NOT NULL,
  api_key_hash  CHAR(64)     NOT NULL UNIQUE,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- 2. CUSTOMERS
CREATE TABLE customers (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid       CHAR(36)     NOT NULL UNIQUE,
  name       VARCHAR(150) NOT NULL,
  email      VARCHAR(190) NOT NULL UNIQUE,
  phone      VARCHAR(30)  NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- 3. ORDERS
CREATE TABLE orders (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36)    NOT NULL UNIQUE,
  merchant_id  BIGINT UNSIGNED NOT NULL,
  customer_id  BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(50) NOT NULL,
  amount       DECIMAL(18,2) NOT NULL CHECK (amount > 0),
  currency     CHAR(3)     NOT NULL DEFAULT 'IDR',
  created_at   TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_orders_merchant_number (merchant_id, order_number),
  CONSTRAINT fk_orders_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

-- 4. PAYMENT_TOKENS (token vault, mock)
CREATE TABLE payment_tokens (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token             VARCHAR(64)  NOT NULL UNIQUE,
  customer_id       BIGINT UNSIGNED NOT NULL,
  token_type        ENUM('card','bank_account','ewallet') NOT NULL,
  brand             VARCHAR(30)  NULL,
  last4             CHAR(4)      NOT NULL,
  fingerprint       CHAR(64)     NOT NULL,
  encrypted_payload TEXT         NOT NULL,
  expires_at        TIMESTAMP NULL,
  revoked_at        TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_tokens_fingerprint (fingerprint),
  CONSTRAINT fk_tokens_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

-- 5. IDEMPOTENCY_KEYS
CREATE TABLE idempotency_keys (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id     BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  endpoint        VARCHAR(150) NOT NULL,
  request_hash    CHAR(64)     NOT NULL,
  status          ENUM('processing','completed') NOT NULL DEFAULT 'processing',
  response_code   SMALLINT UNSIGNED NULL,
  response_body   JSON NULL,
  transaction_id  BIGINT UNSIGNED NULL,
  locked_at       TIMESTAMP NULL,
  expires_at      TIMESTAMP NOT NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_idem (merchant_id, idempotency_key, endpoint),
  KEY idx_idem_expires (expires_at),
  CONSTRAINT fk_idem_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id)
) ENGINE=InnoDB;

-- 6. TRANSACTIONS
CREATE TABLE transactions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36)    NOT NULL UNIQUE,
  reference        VARCHAR(50) NOT NULL UNIQUE,
  merchant_id      BIGINT UNSIGNED NOT NULL,
  order_id         BIGINT UNSIGNED NOT NULL,
  payment_token_id BIGINT UNSIGNED NULL,
  amount           DECIMAL(18,2) NOT NULL CHECK (amount > 0),
  currency         CHAR(3)     NOT NULL DEFAULT 'IDR',
  status           ENUM('pending','proceed','expired') NOT NULL DEFAULT 'pending',
  -- satuan terkecil untuk smart contract (IDR 1.500,00 -> 150000)
  amount_minor     BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(ROUND(amount * 100, 0) AS UNSIGNED)) STORED,
  gateway_ref      VARCHAR(100) NULL,                -- diisi saat proceed (mock: MOCKGW-xxxxxxxxxxxxxxxx)
  expires_at       TIMESTAMP NOT NULL,
  paid_at          TIMESTAMP NULL,
  expired_at       TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_trx_order (order_id),
  KEY idx_trx_status_expires (status, expires_at),
  KEY idx_trx_merchant_created (merchant_id, created_at),
  CONSTRAINT fk_trx_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id),
  CONSTRAINT fk_trx_order    FOREIGN KEY (order_id)    REFERENCES orders(id),
  CONSTRAINT fk_trx_token    FOREIGN KEY (payment_token_id) REFERENCES payment_tokens(id),
  CONSTRAINT chk_trx_state CHECK (
    (status = 'pending' AND paid_at IS NULL AND expired_at IS NULL) OR
    (status = 'proceed' AND paid_at IS NOT NULL AND payment_token_id IS NOT NULL AND expired_at IS NULL
       AND gateway_ref IS NOT NULL AND gateway_ref <> '') OR
    (status = 'expired' AND paid_at IS NULL AND expired_at IS NOT NULL)
  )
) ENGINE=InnoDB;

ALTER TABLE idempotency_keys
  ADD CONSTRAINT fk_idem_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id);

-- 7. TRANSACTION_STATE_HISTORIES
CREATE TABLE transaction_state_histories (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  from_status    ENUM('pending','proceed','expired') NULL,
  to_status      ENUM('pending','proceed','expired') NOT NULL,
  reason         VARCHAR(255) NULL,
  created_at     TIMESTAMP NULL,
  KEY idx_hist_trx (transaction_id),
  CONSTRAINT fk_hist_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

-- 8. AUDIT_LOGS (append-only, hash chain)
CREATE TABLE audit_logs (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NULL,
  event_type     VARCHAR(50) NOT NULL,
  payload        JSON NOT NULL,
  payload_hash   CHAR(64) NOT NULL,
  prev_hash      CHAR(64) NOT NULL,                 -- 64 nol untuk genesis
  record_hash    CHAR(64) NOT NULL UNIQUE,
  created_at     TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_audit_trx (transaction_id),
  CONSTRAINT fk_audit_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

-- 9. BLOCKCHAIN_RECEIPTS (outbox -> PaymentReceiptRegistry di Sepolia; hanya transaksi proceed)
CREATE TABLE blockchain_receipts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id    BIGINT UNSIGNED NOT NULL UNIQUE,   -- maks 1 receipt per transaksi
  audit_log_id      BIGINT UNSIGNED NOT NULL,          -- event transaction.proceed
  -- argumen recordReceipt():
  contract_order_id VARCHAR(50)  NOT NULL UNIQUE,      -- = transactions.reference (kunci unik di kontrak)
  receipt_hash      CHAR(66)     NOT NULL,             -- 0x + 64 hex, keccak256 (bukan SHA-256)
  amount_minor      BIGINT UNSIGNED NOT NULL CHECK (amount_minor > 0),
  settlement_time   BIGINT UNSIGNED NOT NULL CHECK (settlement_time > 0),  -- unix detik
  gateway_ref       VARCHAR(100) NOT NULL,
  currency          CHAR(3)      NOT NULL,
  -- hasil on-chain:
  network           VARCHAR(20)  NOT NULL DEFAULT 'sepolia',
  contract_address  CHAR(42)     NULL,
  tx_hash           CHAR(66)     NULL UNIQUE,
  block_number      BIGINT UNSIGNED NULL,
  status            ENUM('pending','submitted','confirmed','failed') NOT NULL DEFAULT 'pending',
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error        VARCHAR(500) NULL,
  next_attempt_at   TIMESTAMP NULL,
  submitted_at      TIMESTAMP NULL,
  confirmed_at      TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY idx_receipt_queue (status, next_attempt_at),
  CONSTRAINT fk_receipt_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_receipt_audit FOREIGN KEY (audit_log_id) REFERENCES audit_logs(id)
) ENGINE=InnoDB;

-- TRIGGERS
DELIMITER $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END$$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END$$
CREATE TRIGGER trg_trx_final_state BEFORE UPDATE ON transactions
FOR EACH ROW BEGIN
  IF OLD.status IN ('proceed','expired') AND NEW.status <> OLD.status THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proceed/expired is final';
  END IF;
END$$
DELIMITER ;
```

> Catatan untuk agent: terjemahkan DDL ke **Laravel migration** (satu file per tabel, urutan sesuai dependensi FK). Kolom generated, CHECK, dan trigger ditulis dengan `DB::statement()` / `DB::unprepared()` di migration terpisah. Sertakan `down()` yang benar. Gunakan MariaDB juga untuk environment test (bukan SQLite).

## 6. Spesifikasi Implementasi Laravel

### 6.1 Struktur
```
app/
  Models/            Merchant, Customer, Order, PaymentToken, Transaction,
                     IdempotencyKey, TransactionStateHistory, AuditLog, BlockchainReceipt
  Enums/             TransactionStatus (pending, proceed, expired), ReceiptStatus
  Services/
    IdempotencyChecker.php
    TokenizationService.php
    AuditLogService.php
    BlockchainReceiptService.php   (membuat outbox & memproses antrean)
    ReceiptHasher.php              (keccak256 sesuai kontrak)
  Contracts/BlockchainGateway.php  (interface)
  Gateways/MockBlockchainGateway.php
  Gateways/HttpBlockchainGateway.php   (utama)
  Gateways/CliBlockchainGateway.php    (cadangan)
  Console/Commands/                transactions:expire, idempotency:purge, blockchain:submit
database/  migrations/, seeders/, factories/
```

### 6.2 Kontrak Service
- `IdempotencyChecker::handle(Merchant $m, string $key, string $endpoint, array $payload, Closure $process): Response`
- `TokenizationService::tokenize(Customer $c, array $mockData): PaymentToken`
- `Transaction::pending(Order $o): Transaction`: cek batas 4.5, set `expires_at`, tulis history + audit `transaction.created`.
- `$transaction->proceed(PaymentToken $t)` dan `$transaction->expire()`: lihat 4.1; keduanya `DB::transaction()` + `lockForUpdate()`.
- `AuditLogService::record(string $event, ?Transaction $t, array $payload): AuditLog`: ambil record terakhir dengan `lockForUpdate()` agar rantai tidak bercabang; `payload_hash = sha256(json sorted)`; `record_hash` sesuai 4.4.
- `AuditLogService::verifyChain(): bool`.
- `ReceiptHasher::compute(string $orderId, string $amountMinor, int $settlementTime, string $gatewayRef): string`: lihat 6.4.

### 6.3 Endpoint Minimal (untuk menguji database)

| Method | Path | Idempotent | Fungsi |
|---|---|---|---|
| POST | `/api/v1/tokens` | tidak | Tokenisasi data mock |
| POST | `/api/v1/transactions` | **ya** | Buat transaksi (`pending`) untuk sebuah order |
| POST | `/api/v1/transactions/{uuid}/pay` | **ya** | Bayar (`proceed`) atau ditolak jika expired |
| GET | `/api/v1/transactions/{uuid}` | - | Detail + history + status receipt blockchain |
| GET | `/api/v1/orders/{uuid}/transactions` | - | Semua transaksi sebuah order |
| GET | `/api/v1/audit/verify` | - | Verifikasi hash chain |

Autentikasi: header `X-Api-Key` dicocokkan dengan `merchants.api_key_hash`.

### 6.4 Batas Integrasi Blockchain

**Perhitungan `receiptHash`** (harus identik dengan kontrak):

```
keccak256( abi.encodePacked(orderId, amount, settlementTime, gatewayRef) )
          = keccak256( bytes(orderId) ‖ uint256_32byte_bigendian(amount) ‖ uint256_32byte_bigendian(settlementTime) ‖ bytes(gatewayRef) )
```
- `orderId` dan `gatewayRef`: byte UTF-8 mentah, tanpa padding dan tanpa penanda panjang.
- `amount` dan `settlementTime`: masing-masing tepat 32 byte, big-endian.
- Hasil disimpan sebagai `0x` + 64 hex.
- **Jebakan di PHP**: `hash('sha3-256', ...)` **bukan** Keccak-256 Ethereum (padding-nya berbeda). Gunakan library Keccak, mis. `kornrunner/keccak`.
- Verifikasi silang: kontrak menyediakan `computeReceiptHash(...)` (bisa dipanggil via `eth_call`); hasil hitungan Laravel harus sama dengan hasil fungsi itu.

```php
interface BlockchainGateway
{
    /** view: hasReceipt(orderId) */
    public function hasReceipt(string $contractOrderId): bool;

    /** tx: recordReceipt(orderId, receiptHash, amount, settlementTime, gatewayRef, currency) */
    public function recordReceipt(BlockchainReceipt $receipt): ReceiptResult; // tx_hash, block_number, status

    /** view: verifyReceipt(orderId, amount, settlementTime, gatewayRef) -> (valid, storedHash) */
    public function verifyReceipt(BlockchainReceipt $receipt): bool;
}
```

- Implementasi awal: `MockBlockchainGateway` (menyimpan "receipt" di memori/tabel dan meniru perilaku kontrak, termasuk menolak `orderId` duplikat; `tx_hash` palsu `0x` + 64 hex), dipilih lewat `BLOCKCHAIN_DRIVER=mock`.
- Driver nyata: `HttpBlockchainGateway` (`BLOCKCHAIN_DRIVER=http`, **utama**) dan `CliBlockchainGateway` (`BLOCKCHAIN_DRIVER=cli`, cadangan). Keduanya memanggil layanan Node.js + **ethers.js v6** milik rekan (Hardhat, jaringan `sepolia`) dengan kontrak data yang sama (lihat 6.5). Database dan service Laravel lain tidak berubah; hanya driver yang dipilih lewat `.env`.
- ABI yang relevan: `recordReceipt`, `hasReceipt`, `verifyReceipt`, `getReceipt`, `computeReceiptHash`. ABI lengkap ada di artefak Hardhat rekan (`artifacts/contracts/PaymentReceiptRegistry.sol/PaymentReceiptRegistry.json`); alamat kontrak ada di `deployments/sepolia.json` hasil `deploy.js`.

### 6.5 Kontrak Layanan Blockchain (Laravel ↔ layanan Node.js rekan)

**Latar belakang**: `deploy.js` rekan memakai Hardhat + ethers.js v6, dan wallet deployer (`getSigners()[0]`) menjadi **owner** kontrak. Owner otomatis boleh memanggil `recordReceipt`, jadi jika wallet yang sama dipakai untuk menandatangani, **`setGatewayAuthorization` tidak diperlukan**. Data deployment (`deployments/sepolia.json`, 2026-11-09): **alamat kontrak** `0x26B92A0F7D3d9E97A8A3DDaEa1c2CB6068EbD7aD` dan **wallet deployer/owner** `0x9c8d8b3585fEf637A6A151FD76f7a2c3648BB679`. Keduanya alamat publik; private key wallet deployer tetap hanya di `.env` rekan.

**Mengapa HTTP menjadi pilihan utama (CLI cadangan):**
- Wallet yang sama menandatangani semua transaksi, dan Ethereum mewajibkan **nonce berurutan**. Beberapa proses CLI yang berjalan bersamaan dapat bentrok nonce. Satu layanan HTTP dapat menyerialkan pengiriman dalam satu antrean.
- CLI memulai ulang Node dan memuat Hardhat/ethers pada setiap pemanggilan (lambat), serta rawan masalah kutip/escape argumen di Windows (Laragon).
- Tetap sediakan CLI sebagai cadangan (mis. jika layanan HTTP belum siap saat demo).

**Aturan umum (HTTP maupun CLI):**
- `amount` dikirim sebagai **string** bilangan bulat (bukan number JSON) agar aman untuk `uint256` di ethers v6 (BigInt). `settlementTime` boleh integer.
- Satuan `amount` = **satuan terkecil, x100** (IDR 1.500,00 → `"150000"`), konsisten dengan komentar kontrak dan kolom `amount_minor`.
- `receiptHash` selalu dihitung Laravel; layanan **wajib memverifikasinya** terhadap `ethers.solidityPackedKeccak256(["string","uint256","uint256","string"], [orderId, amount, settlementTime, gatewayRef])` sebelum mengirim, dan menolak jika berbeda (`422 HASH_MISMATCH`).
- Layanan hanya mendengarkan `127.0.0.1` dan menolak request tanpa header `X-Internal-Token` sama dengan `BLOCKCHAIN_SERVICE_TOKEN`.
- Layanan menyerialkan pengiriman transaksi (satu antrean) untuk menghindari konflik nonce.

**Endpoint HTTP:**

| Method | Path | Body / Respons |
|---|---|---|
| GET | `/health` | `{ "ok": true, "network": "sepolia", "signer": "0x...", "contract": "0x..." }` |
| GET | `/receipts/{orderId}` | `{ "exists": true\|false, "status": "SETTLED"? }` (memakai `hasReceipt`/`getStatus`) |
| POST | `/receipts` | Body: `{ "orderId", "receiptHash", "amount", "settlementTime", "gatewayRef", "currency" }`. Sukses `200`: `{ "txHash", "blockNumber", "status": "confirmed" }` (menunggu 1 konfirmasi) |
| POST | `/receipts/verify` | Body: `{ "orderId", "amount", "settlementTime", "gatewayRef" }` → `{ "valid": true\|false, "storedHash": "0x..." }` |

**Kode error** (body `{ "code", "message" }`): `409 ALREADY_RECORDED` (kontrak revert "order ID already recorded"), `422 HASH_MISMATCH` / `422 INVALID_INPUT` / `422 CONTRACT_REVERT` (alasan revert disertakan), `401 UNAUTHORIZED`, `502 RPC_ERROR` (RPC Sepolia bermasalah), `504 CONFIRMATION_TIMEOUT` (transaksi sudah dikirim tetapi belum terkonfirmasi; sertakan `txHash` bila ada).

**Perilaku Laravel terhadap respons:**
- `200` → receipt `confirmed`, simpan `tx_hash`, `block_number`.
- `409 ALREADY_RECORDED` → panggil `/receipts/verify`; jika `valid=true` tandai `confirmed`, jika tidak tandai `failed` dengan `last_error` (kemungkinan data tidak cocok dan perlu ditinjau manual).
- `504`/timeout/`502` → status `submitted` (simpan `tx_hash` jika ada), retry dengan backoff; sebelum retry **selalu** cek `GET /receipts/{orderId}` agar tidak mencatat ganda.
- `422` → `failed` (tidak di-retry otomatis).
- Timeout HTTP Laravel: 60 detik (Sepolia bisa butuh puluhan detik).

**Alternatif CLI** (kontrak data identik): `node scripts/record-receipt.js` membaca **JSON dari stdin** (menghindari masalah escape di Windows) dan mencetak **satu objek JSON ke stdout** dengan bentuk yang sama seperti respons HTTP; exit code `0` = sukses, selain itu `{ "code", "message" }` di stdout. Subperintah: `record`, `exists`, `verify`.

**Data uji dari rekan**: minta rekan menghasilkan minimal 3 *test vector* `receiptHash` memakai `ethers.solidityPackedKeccak256` (input: `orderId`, `amount`, `settlementTime`, `gatewayRef`) untuk dipakai pada unit test `ReceiptHasher` di PHP (AC17).

## 7. Seeder Mock Data
- 2 merchant (API key plaintext dicetak ke console saat seeding; hanya hash yang disimpan).
- 10 customer, 20 order, 15 payment token (mock, via tokenisasi).
- Transaksi mencakup semua skenario: `pending` (belum lewat waktu), `proceed`, `expired`, serta minimal **1 order dengan transaksi `expired` lalu transaksi `proceed`** (membuktikan 1:N).
- History dan audit log konsisten; hash chain valid.
- Setiap transaksi `proceed` punya `gateway_ref` (`MOCKGW-...`), receipt dengan `receipt_hash` keccak256 yang valid, dan nilai itu ada di payload audit `transaction.proceed`. Sebagian receipt `confirmed` (dengan `tx_hash` contoh), sebagian `pending`.

## 8. Indeks & Performa
Query kritis yang harus memakai indeks:
- Lookup idempotency: `merchant_id + idempotency_key + endpoint` (unique).
- Expiry job: `WHERE status='pending' AND expires_at <= NOW()` (`idx_trx_status_expires`).
- Antrean receipt: `WHERE status IN ('pending') AND next_attempt_at <= NOW()` (`idx_receipt_queue`).
- Daftar transaksi per merchant dan tanggal.

## 9. Kriteria Penerimaan

| # | Kriteria | Cara Uji |
|---|---|---|
| AC1 | `php artisan migrate` berhasil pada database kosong di Laragon, lalu `php artisan migrate:fresh --seed` berhasil; ke-9 tabel domain terbentuk | Jalankan command |
| AC2 | Transaksi baru berstatus `pending`, `paid_at` NULL, `expires_at` terisi sesuai konfigurasi | Feature test |
| AC3 | `proceed()` mengubah `pending → proceed`, mengisi `paid_at`, menambah 1 history, 1 audit log, dan 1 baris `blockchain_receipts` (`pending`) dalam satu DB transaction | Feature test |
| AC4 | Create transaksi 2x dengan key & body sama → 1 baris `transactions`, response ke-2 identik + `Idempotent-Replayed: true` | Feature test |
| AC5 | Key sama, body berbeda → `422` | Feature test |
| AC6 | 10 request paralel dengan key sama → tepat 1 transaksi | Concurrency test |
| AC7 | Bayar transaksi yang sudah `proceed` (key baru) tidak mengubah data, tidak menambah audit log maupun receipt | Feature test |
| AC8 | `UPDATE` status `proceed`/`expired` ke status lain lewat SQL langsung ditolak trigger | SQL test |
| AC9 | `UPDATE`/`DELETE` apa pun pada `audit_logs` ditolak | SQL test |
| AC10 | `verifyChain()` `true` pada data seed; `false` jika satu baris dimanipulasi (di test tanpa trigger) | Unit test |
| AC11 | Tidak ada nomor kartu penuh/CVV di kolom mana pun | Inspeksi dump |
| AC12 | `transactions:expire` mengubah `pending` yang lewat waktu menjadi `expired` (+ history + audit `transaction.expired`, **tanpa** receipt), dan tidak menyentuh yang belum lewat | Feature test |
| AC13 | Bayar transaksi yang lewat `expires_at` (scheduler belum jalan) → `422 transaction_expired`, status menjadi `expired` (lazy expiry) | Feature test |
| AC14 | Satu order dapat memiliki beberapa transaksi (`pending`, `expired`, `proceed` bersamaan); setiap transaksi punya `reference` unik | Feature test |
| AC15 | Dua transaksi `proceed` pada order yang sama menghasilkan dua receipt dengan `contract_order_id` berbeda (tidak bentrok di kontrak) | Feature test |
| AC16 | `blockchain:submit` (driver mock) mengubah receipt `pending → confirmed` dan mengisi `tx_hash`; kegagalan menambah `attempts` tanpa mengubah status transaksi; pengiriman ulang untuk `contract_order_id` yang sudah tercatat tidak membuat tx baru (dianggap `confirmed`) | Feature test |
| AC17 | `receipt_hash` = keccak256 dari `reference ‖ amount_minor ‖ settlement_time ‖ gateway_ref` sesuai kontrak (diuji dengan test vector; pada tahap Sepolia dicocokkan dengan `computeReceiptHash`), dan nilainya ada di payload audit `transaction.proceed` | Unit + feature test |
| AC18 | Transaksi berstatus `proceed` tanpa `gateway_ref` ditolak CHECK constraint | SQL test |
| AC19 | `HttpBlockchainGateway` (diuji dengan `Http::fake`): `200` → `confirmed`; `409 ALREADY_RECORDED` + verify valid → `confirmed`; `504`/timeout → `submitted` lalu retry tidak mencatat ganda (cek `GET /receipts/{orderId}` terlebih dulu); `422` → `failed` | Feature test |
| AC20 | `ReceiptHasher` cocok dengan seluruh *test vector* `ethers.solidityPackedKeccak256` dari rekan | Unit test |
| AC21 | `blockchain:submit` tidak berjalan tumpang tindih dan memproses receipt berurutan | Feature test |

## 10. Konfigurasi Environment (`.env`)

```
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=                     # isi dengan nama database yang sudah dibuat di Laragon
DB_USERNAME=root
DB_PASSWORD=

TOKEN_FINGERPRINT_KEY=ganti_dengan_secret_acak
TRANSACTION_EXPIRY_MINUTES=60
IDEMPOTENCY_TTL_HOURS=24
IDEMPOTENCY_STALE_SECONDS=60

BLOCKCHAIN_DRIVER=mock            # mock | http | cli
BLOCKCHAIN_MAX_ATTEMPTS=5
BLOCKCHAIN_HTTP_TIMEOUT=60

# Layanan Node.js + ethers.js milik rekan
BLOCKCHAIN_SERVICE_URL=http://127.0.0.1:3001
BLOCKCHAIN_SERVICE_TOKEN=ganti_dengan_secret_acak
# BLOCKCHAIN_CLI_COMMAND="node scripts/record-receipt.js"   # hanya jika driver=cli

# Informasi publik (bukan rahasia)
RECEIPT_NETWORK=sepolia
RECEIPT_CONTRACT_ADDRESS=0x26B92A0F7D3d9E97A8A3DDaEa1c2CB6068EbD7aD   # alamat KONTRAK di Sepolia
RECEIPT_SIGNER_ADDRESS=0x9c8d8b3585fEf637A6A151FD76f7a2c3648BB679     # wallet deployer/owner (penandatangan)

# Private key wallet TIDAK ada di Laravel; hanya di .env layanan rekan (jangan di-commit)
```

Scheduler (`routes/console.php`): `transactions:expire` tiap menit, `idempotency:purge` harian, dan `blockchain:submit` tiap menit dengan `withoutOverlapping()` serta memproses receipt **berurutan** (satu per satu), karena wallet yang sama memakai nonce berurutan.

## 11. Urutan Pengerjaan (untuk agent)
0. Pastikan `.env` mengarah ke database kosong yang sudah ada (`DB_DATABASE`), dan koneksi berhasil (`php artisan migrate:status`).
1. Enum + semua migration (urutan: merchants, customers, orders, payment_tokens, transactions, idempotency_keys [+FK], transaction_state_histories, audit_logs, blockchain_receipts, lalu trigger).
2. Model, relasi, factory.
3. `TokenizationService`, `AuditLogService`, `IdempotencyChecker`.
4. `Transaction::pending()`, `proceed()`, `expire()` + command `transactions:expire`.
5. `ReceiptHasher` (keccak256), `BlockchainReceiptService`, interface `BlockchainGateway`, `MockBlockchainGateway`, command `blockchain:submit`.
6. Seeder (Bagian 7).
7. Endpoint minimal (6.3).
8. Test AC1–AC17.
9. `HttpBlockchainGateway` (dan `CliBlockchainGateway`) sesuai Bagian 6.5; uji end-to-end ke Sepolia setelah layanan rekan berjalan dan `RECEIPT_CONTRACT_ADDRESS` terisi.

## 12. Risiko & Catatan
- Trigger/CHECK/generated column membutuhkan MariaDB ≥ 10.2.1 (disarankan ≥ 10.6); jangan uji dengan SQLite.
- Hash chain membuat penulisan audit log **serial** (lock baris terakhir); cukup untuk prototipe.
- Satu order dapat memiliki lebih dari satu transaksi `proceed` (pembayaran ganda tidak dicegah database; lihat 4.5).
- Kontrak menolak `orderId` duplikat dan receipt `SETTLED` tidak dapat diubah. Receipt yang salah catat tidak bisa diperbaiki on-chain, jadi data (`reference`, `amount_minor`, `paid_at`, `gateway_ref`) harus final sebelum dikirim dan pengiriman harus idempoten (4.4 langkah 4).
- Status `expired` tidak bisa direkam di kontrak ini (tidak ada cara membuat receipt non-SETTLED), sehingga bukti `expired` hanya ada di `audit_logs` off-chain.
- `amount` harus dikirim sebagai **string** (bukan number) ke layanan ethers.js agar tidak kehilangan presisi pada `uint256`.
- Beberapa pengiriman paralel dari wallet yang sama berisiko bentrok nonce; karena itu pengiriman diserialkan (6.5).
- Receipt yang sudah tercatat di Sepolia tidak dapat dihapus/diubah; gunakan data mock yang bersih saat uji ke Sepolia (setiap `reference` hanya bisa dicatat sekali, sehingga `migrate:fresh` yang menghasilkan `reference` yang sama dapat bentrok dengan receipt lama di kontrak; gunakan prefix `reference` yang unik per siklus uji atau deploy kontrak baru).
- Receipt on-chain bersifat eventual (bisa tertunda atau gagal); UI/API harus menampilkan status receipt secara terpisah dari status transaksi.
- Private key wallet Sepolia tidak boleh masuk repository atau database.

## 13. Keputusan & Pertanyaan Terbuka

### Keputusan final
| # | Topik | Keputusan |
|---|---|---|
| Q1 | Smart contract | `PaymentReceiptRegistry.sol` (sudah dibaca). Receipt per transaksi `proceed`; `orderId` kontrak = `transactions.reference` |
| Q2 | Order : transaksi | 1 : N, **tanpa** batas transaksi aktif |
| Q3 | State & expired | `pending`, `proceed`, `expired`; expired otomatis setelah 60 menit (`TRANSACTION_EXPIRY_MINUTES`) |
| Q4 | Metode pembayaran mock | `card`, `bank_account`, `ewallet` |
| Q5 | Sisi blockchain | Dikerjakan rekan: Hardhat + ethers.js v6, jaringan Sepolia, wallet sendiri |
| Q6 | Hash receipt | Keccak-256 sesuai kontrak, terpisah dari SHA-256 audit chain; keduanya dihubungkan lewat payload audit |
| Q7 | `expired` on-chain | Tidak dicatat on-chain (kontrak tidak mendukung); hanya off-chain |
| Q8 | Cara pemanggilan | Rekan menyatakan bisa HTTP dan CLI. **Diputuskan: HTTP utama, CLI cadangan**, kontrak data di 6.5 |
| Q9 | Alamat on-chain | Kontrak: `0x26B92A0F7D3d9E97A8A3DDaEa1c2CB6068EbD7aD`. Wallet deployer/owner (penandatangan): `0x9c8d8b3585fEf637A6A151FD76f7a2c3648BB679`. Layanan rekan harus menandatangani dengan wallet deployer ini, sehingga `setGatewayAuthorization` tidak diperlukan |
| Q10 | Satuan `amount` | Satuan terkecil x100 (`amount_minor`), dikirim sebagai string |

### Masih terbuka (tanyakan ke rekan blockchain)
| # | Pertanyaan | Default sementara |
|---|---|---|
| T5 | ~~Alamat kontrak~~ | **Selesai** (lihat Q9) |
| T7 | ~~Wallet penandatangan~~ | **Selesai**: deployer = owner kontrak (lihat Q9) |
| T8 | Rekan menyediakan layanan HTTP sesuai 6.5 (port, token) dan 3 *test vector* hash | Menunggu rekan |
