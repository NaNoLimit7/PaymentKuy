// blockchain/service.js
// Microservice HTTP Blockchain untuk PaymentKuy (Sesuai PRD Bagian 6.5)
// Endpoint: http://127.0.0.1:3001
// Mendengarkan request dari Laravel via X-Internal-Token

const http = require('http');
const path = require('path');
const fs   = require('fs');
require('dotenv').config({ path: path.resolve(__dirname, '.env'), override: true });
const { ethers } = require('ethers');

// ── Configuration ─────────────────────────────────────────────────────────────
const PORT  = process.env.BLOCKCHAIN_SERVICE_PORT || 3001;
const HOST  = '127.0.0.1';
const TOKEN = process.env.BLOCKCHAIN_SERVICE_TOKEN || 'pk_internal_service_token_sepolia_2026';

const rpcUrl     = process.env.SEPOLIA_RPC_URL;
const privateKey = process.env.DEPLOYER_PRIVATE_KEY;

if (!rpcUrl || !privateKey) {
    console.error(' [ERROR] SEPOLIA_RPC_URL atau DEPLOYER_PRIVATE_KEY belum diset di blockchain/.env');
    process.exit(1);
}

// ── Contract Artifact & Deployments ───────────────────────────────────────────
const deploymentsPath = path.join(__dirname, 'deployments/sepolia.json');
const artifactPath    = path.join(__dirname, 'artifacts/contracts/PaymentReceiptRegistry.sol/PaymentReceiptRegistry.json');

let contractAddress, abi;
try {
    const deployments = JSON.parse(fs.readFileSync(deploymentsPath, 'utf8'));
    contractAddress   = process.env.CONTRACT_ADDRESS || deployments.contractAddress;

    const artifact = JSON.parse(fs.readFileSync(artifactPath, 'utf8'));
    abi            = artifact.abi;
} catch (e) {
    console.error(' [ERROR] Gagal memuat artifact kontrak:', e.message);
    process.exit(1);
}

// ── Provider & Wallet Setup ───────────────────────────────────────────────────
const provider = new ethers.JsonRpcProvider(rpcUrl);
const wallet   = new ethers.Wallet(privateKey, provider);
const contract = new ethers.Contract(contractAddress, abi, wallet);

// ── Serial Transaction Queue (Anti Nonce Conflict) ────────────────────────────
// Wallet yang sama menandatangani semua tx, sehingga harus dieksekusi secara serial
let txQueue = Promise.resolve();
function enqueueTransaction(fn) {
    return new Promise((resolve, reject) => {
        txQueue = txQueue
            .then(() => fn())
            .then(resolve)
            .catch(reject);
    });
}

// ── Helper: JSON Response ─────────────────────────────────────────────────────
function sendJson(res, statusCode, data) {
    if (res.headersSent) return;
    res.writeHead(statusCode, {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Headers': 'Content-Type, X-Internal-Token',
    });
    res.end(JSON.stringify(data, (key, value) => typeof value === 'bigint' ? value.toString() : value));
}

// ── Helper: Read Request Body ─────────────────────────────────────────────────
function readBody(req) {
    return new Promise((resolve, reject) => {
        let body = '';
        req.on('data', chunk => { body += chunk; });
        req.on('end', () => {
            if (!body) return resolve({});
            try {
                resolve(JSON.parse(body));
            } catch (err) {
                reject(new Error('Invalid JSON payload'));
            }
        });
        req.on('error', reject);
    });
}

// ── Request Router ────────────────────────────────────────────────────────────
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, `http://${req.headers.host}`);
    const pathname = url.pathname;
    const method   = req.method.toUpperCase();

    // CORS preflight
    if (method === 'OPTIONS') {
        res.writeHead(204, {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type, X-Internal-Token',
        });
        res.end();
        return;
    }

    try {
        // ── 1. GET /health (Public / Healthcheck) ──────────────────────────────
        if (method === 'GET' && pathname === '/health') {
            const balance = await provider.getBalance(wallet.address);
            return sendJson(res, 200, {
                ok:       true,
                network:  'sepolia',
                signer:   wallet.address,
                contract: contractAddress,
                balance:  ethers.formatEther(balance) + ' ETH',
            });
        }

        // ── Auth Check for remaining endpoints ───────────────────────────────
        const authHeader = req.headers['x-internal-token'];
        if (authHeader !== TOKEN) {
            return sendJson(res, 401, {
                code:    'UNAUTHORIZED',
                message: 'Invalid or missing X-Internal-Token header',
            });
        }

        // ── 2. GET /receipts/:orderId ─────────────────────────────────────────
        const getReceiptMatch = pathname.match(/^\/receipts\/([^\/]+)$/);
        if (method === 'GET' && getReceiptMatch) {
            const orderId = decodeURIComponent(getReceiptMatch[1]);
            const exists  = await contract.hasReceipt(orderId);

            if (!exists) {
                return sendJson(res, 200, { exists: false });
            }

            return sendJson(res, 200, {
                exists: true,
                status: 'SETTLED',
            });
        }

        // ── 3. POST /receipts (Record Receipt On-Chain) ───────────────────────
        if (method === 'POST' && pathname === '/receipts') {
            const body = await readBody(req);
            const { orderId, receiptHash, amount, settlementTime, gatewayRef, currency } = body;

            // Validasi parameter wajib
            if (!orderId || !receiptHash || !amount || !settlementTime || !gatewayRef) {
                return sendJson(res, 422, {
                    code:    'INVALID_INPUT',
                    message: 'Missing required fields: orderId, receiptHash, amount, settlementTime, gatewayRef',
                });
            }

            // Verifikasi Receipt Hash (PRD 6.5: layanan wajib memverifikasi terhadap keccak256)
            const expectedHash = ethers.solidityPackedKeccak256(
                ['string', 'uint256', 'uint256', 'string'],
                [orderId, BigInt(amount), BigInt(settlementTime), gatewayRef]
            );

            if (expectedHash.toLowerCase() !== receiptHash.toLowerCase()) {
                return sendJson(res, 422, {
                    code:         'HASH_MISMATCH',
                    message:      'Calculated hash does not match payload receiptHash',
                    expectedHash: expectedHash,
                    receivedHash: receiptHash,
                });
            }

            // Cek apakah orderId sudah pernah dicatat di kontrak
            const alreadyExists = await contract.hasReceipt(orderId);
            if (alreadyExists) {
                return sendJson(res, 409, {
                    code:    'ALREADY_RECORDED',
                    message: `Order ID ${orderId} already recorded on-chain`,
                });
            }

            // Eksekusi transaksi via Antrean Serial (Nonce safety)
            const result = await enqueueTransaction(async () => {
                const hashBytes32 = receiptHash.startsWith('0x') ? receiptHash : '0x' + receiptHash;
                const curr        = currency || 'IDR';

                console.log(`[SERVICE] Mengirim recordReceipt() untuk: ${orderId}`);
                const tx = await contract.recordReceipt(
                    orderId,
                    hashBytes32,
                    BigInt(amount),
                    BigInt(settlementTime),
                    gatewayRef,
                    curr
                );

                console.log(`[SERVICE] TX Broadcasted: ${tx.hash}. Menunggu 1 konfirmasi...`);
                const receipt = await tx.wait(1);
                console.log(`[SERVICE] TX Confirmed di Block #${receipt.blockNumber}`);

                return {
                    txHash:      tx.hash,
                    blockNumber: receipt.blockNumber,
                    status:      'confirmed',
                };
            });

            return sendJson(res, 200, result);
        }

        // ── 4. POST /receipts/verify ──────────────────────────────────────────
        if (method === 'POST' && pathname === '/receipts/verify') {
            const body = await readBody(req);
            const { orderId, amount, settlementTime, gatewayRef } = body;

            if (!orderId || !amount || !settlementTime || !gatewayRef) {
                return sendJson(res, 422, {
                    code:    'INVALID_INPUT',
                    message: 'Missing required fields: orderId, amount, settlementTime, gatewayRef',
                });
            }

            const exists = await contract.hasReceipt(orderId);
            if (!exists) {
                return sendJson(res, 200, { valid: false, storedHash: null });
            }

            const [valid, storedHash] = await contract.verifyReceipt(
                orderId,
                BigInt(amount),
                BigInt(settlementTime),
                gatewayRef
            );

            return sendJson(res, 200, {
                valid:      Boolean(valid),
                storedHash: String(storedHash),
            });
        }

        // 404 Not Found
        return sendJson(res, 404, { code: 'NOT_FOUND', message: 'Endpoint not found' });

    } catch (err) {
        console.error('[SERVICE ERROR]', err);

        const errMsg = err.message || '';
        if (errMsg.includes('order ID already recorded')) {
            return sendJson(res, 409, { code: 'ALREADY_RECORDED', message: 'Order ID already recorded on-chain' });
        }
        if (errMsg.includes('reverted') || errMsg.includes('execution reverted')) {
            return sendJson(res, 422, { code: 'CONTRACT_REVERT', message: errMsg });
        }
        if (errMsg.includes('timeout') || errMsg.includes('timed out')) {
            return sendJson(res, 504, { code: 'CONFIRMATION_TIMEOUT', message: errMsg });
        }

        return sendJson(res, 502, { code: 'RPC_ERROR', message: errMsg });
    }
});

server.listen(PORT, HOST, () => {
    console.log(`================================================================`);
    console.log(` 🚀 PaymentKuy Blockchain HTTP Service running!`);
    console.log(` 🌐 URL     : http://${HOST}:${PORT}`);
    console.log(` 🔗 Network : Sepolia Testnet`);
    console.log(` 📝 Contract: ${contractAddress}`);
    console.log(` 🔑 Signer  : ${wallet.address}`);
    console.log(` 🔒 Token   : ${TOKEN}`);
    console.log(`================================================================`);
});
