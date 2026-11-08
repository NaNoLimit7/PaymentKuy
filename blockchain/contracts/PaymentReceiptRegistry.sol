// SPDX-License-Identifier: MIT
pragma solidity ^0.8.28;

import "@openzeppelin/contracts/access/Ownable.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

/**
 * @title PaymentReceiptRegistry
 * @author PaymentKuy Team — FILKOM Universitas Brawijaya
 * @notice Immutable on-chain audit trail for e-commerce payment receipts.
 *
 * @dev Records a Keccak-256 receipt hash for each settled fiat payment.
 *      Only the authorized gateway backend (owner) can record receipts.
 *      Anyone can verify a receipt using the order ID — no private data stored.
 *
 * Regulatory compliance:
 *   - UU PDP (No. 27/2022): Only hashes are stored; no raw personal data on-chain.
 *   - UU P2SK (No. 4/2023): Immutable ledger prevents transaction manipulation.
 *   - Bank Indonesia PJP standards: Audit trail for every settled transaction.
 */
contract PaymentReceiptRegistry is Ownable, ReentrancyGuard {
    // ─── Enums ────────────────────────────────────────────────────────────────

    /**
     * @notice Lifecycle states of a payment transaction.
     */
    enum PaymentStatus {
        PENDING,   // 0 — Default / initial state
        SETTLED,   // 1 — Payment confirmed and receipt recorded on-chain
        FAILED,    // 2 — Payment denied or cancelled
        EXPIRED    // 3 — QRIS/VA session timed out
    }

    // ─── Structs ──────────────────────────────────────────────────────────────

    /**
     * @notice A single payment receipt entry stored on-chain.
     * @dev    receiptHash = keccak256(abi.encodePacked(orderId, amount, settlementTime, gatewayRef))
     */
    struct Receipt {
        bytes32  receiptHash;      // Keccak-256 proof of the payment data
        uint256  amount;           // Payment amount in smallest currency unit (e.g., IDR cents)
        uint256  settlementTime;   // Unix timestamp of gateway settlement
        string   gatewayRef;       // Gateway reference ID (e.g., Midtrans order ID)
        string   currency;         // ISO 4217 currency code (e.g., "IDR")
        PaymentStatus status;      // Current payment status
        bool     exists;           // Guard against uninitialized reads
    }

    // ─── State ────────────────────────────────────────────────────────────────

    /// @notice Maps orderId → Receipt entry
    mapping(string => Receipt) private _receipts;

    /// @notice Authorized gateway addresses (can call recordReceipt / updateStatus)
    mapping(address => bool) private _authorizedGateways;

    /// @notice Total number of receipts recorded
    uint256 public totalReceipts;

    /// @notice Total number of settled transactions
    uint256 public totalSettled;

    // ─── Events ───────────────────────────────────────────────────────────────

    /**
     * @notice Emitted when a new payment receipt is recorded on-chain.
     */
    event ReceiptRecorded(
        string  indexed orderId,
        bytes32         receiptHash,
        uint256         amount,
        string          currency,
        uint256         settlementTime,
        address         recordedBy
    );

    /**
     * @notice Emitted when a payment status is updated (e.g., PENDING → FAILED).
     */
    event StatusUpdated(
        string        indexed orderId,
        PaymentStatus         oldStatus,
        PaymentStatus         newStatus,
        address               updatedBy
    );

    /**
     * @notice Emitted when a gateway address is authorized or revoked.
     */
    event GatewayAuthorizationChanged(
        address indexed gateway,
        bool            authorized
    );

    // ─── Modifiers ────────────────────────────────────────────────────────────

    modifier onlyAuthorized() {
        require(
            msg.sender == owner() || _authorizedGateways[msg.sender],
            "PaymentReceiptRegistry: caller is not authorized"
        );
        _;
    }

    modifier orderExists(string calldata orderId) {
        require(
            _receipts[orderId].exists,
            "PaymentReceiptRegistry: order ID not found"
        );
        _;
    }

    modifier orderNotExists(string calldata orderId) {
        require(
            !_receipts[orderId].exists,
            "PaymentReceiptRegistry: order ID already recorded"
        );
        _;
    }

    // ─── Constructor ──────────────────────────────────────────────────────────

    /**
     * @param initialOwner The deployer / gateway backend wallet address.
     */
    constructor(address initialOwner) Ownable(initialOwner) {}

    // ─── Core Functions ───────────────────────────────────────────────────────

    /**
     * @notice Record an immutable payment receipt on-chain.
     * @dev    Called by the backend after a webhook settlement confirmation.
     *         The receipt hash is computed off-chain and verified on-chain via verifyHash().
     *
     * @param orderId        Unique order/transaction ID from the e-commerce platform.
     * @param receiptHash    keccak256(abi.encodePacked(orderId, amount, settlementTime, gatewayRef))
     * @param amount         Payment amount in smallest unit (e.g., 150000 for Rp 1.500,00).
     * @param settlementTime Unix timestamp of the gateway settlement event.
     * @param gatewayRef     Gateway reference string (e.g., Midtrans transaction_id).
     * @param currency       ISO 4217 currency code (e.g., "IDR").
     */
    function recordReceipt(
        string calldata orderId,
        bytes32         receiptHash,
        uint256         amount,
        uint256         settlementTime,
        string calldata gatewayRef,
        string calldata currency
    )
        external
        nonReentrant
        onlyAuthorized
        orderNotExists(orderId)
    {
        require(bytes(orderId).length > 0,     "PaymentReceiptRegistry: orderId cannot be empty");
        require(receiptHash != bytes32(0),     "PaymentReceiptRegistry: receiptHash cannot be zero");
        require(amount > 0,                    "PaymentReceiptRegistry: amount must be greater than zero");
        require(settlementTime > 0,            "PaymentReceiptRegistry: invalid settlementTime");
        require(bytes(gatewayRef).length > 0,  "PaymentReceiptRegistry: gatewayRef cannot be empty");
        require(bytes(currency).length > 0,    "PaymentReceiptRegistry: currency cannot be empty");

        _receipts[orderId] = Receipt({
            receiptHash:    receiptHash,
            amount:         amount,
            settlementTime: settlementTime,
            gatewayRef:     gatewayRef,
            currency:       currency,
            status:         PaymentStatus.SETTLED,
            exists:         true
        });

        totalReceipts++;
        totalSettled++;

        emit ReceiptRecorded(
            orderId,
            receiptHash,
            amount,
            currency,
            settlementTime,
            msg.sender
        );
    }

    /**
     * @notice Update the status of an existing order (e.g., mark as FAILED or EXPIRED).
     * @dev    Cannot downgrade a SETTLED receipt — immutability guarantee.
     *
     * @param orderId   The order ID to update.
     * @param newStatus The new PaymentStatus value.
     */
    function updateStatus(
        string calldata orderId,
        PaymentStatus   newStatus
    )
        external
        nonReentrant
        onlyAuthorized
        orderExists(orderId)
    {
        Receipt storage receipt = _receipts[orderId];

        require(
            receipt.status != PaymentStatus.SETTLED,
            "PaymentReceiptRegistry: cannot modify a SETTLED receipt"
        );
        require(
            newStatus != receipt.status,
            "PaymentReceiptRegistry: status is already the same"
        );

        PaymentStatus oldStatus = receipt.status;
        receipt.status = newStatus;

        if (oldStatus == PaymentStatus.SETTLED) {
            totalSettled--;
        }

        emit StatusUpdated(orderId, oldStatus, newStatus, msg.sender);
    }

    // ─── View Functions ───────────────────────────────────────────────────────

    /**
     * @notice Retrieve a payment receipt by order ID.
     * @param orderId The unique order/transaction ID.
     * @return The full Receipt struct.
     */
    function getReceipt(string calldata orderId)
        external
        view
        orderExists(orderId)
        returns (Receipt memory)
    {
        return _receipts[orderId];
    }

    /**
     * @notice Check whether an order has a recorded receipt.
     * @param orderId The unique order/transaction ID.
     * @return True if the order has been recorded.
     */
    function hasReceipt(string calldata orderId)
        external
        view
        returns (bool)
    {
        return _receipts[orderId].exists;
    }

    /**
     * @notice Cryptographically verify that stored receipt data matches a given hash.
     * @dev    Recomputes keccak256(abi.encodePacked(orderId, amount, settlementTime, gatewayRef))
     *         and checks it against the stored receiptHash.
     *
     * @param orderId        The order ID to verify.
     * @param amount         Claimed payment amount.
     * @param settlementTime Claimed settlement timestamp.
     * @param gatewayRef     Claimed gateway reference ID.
     * @return valid         True if the hash matches — the receipt is authentic.
     * @return storedHash    The on-chain stored hash for reference.
     */
    function verifyReceipt(
        string calldata orderId,
        uint256         amount,
        uint256         settlementTime,
        string calldata gatewayRef
    )
        external
        view
        orderExists(orderId)
        returns (bool valid, bytes32 storedHash)
    {
        Receipt storage receipt = _receipts[orderId];
        bytes32 computedHash = keccak256(
            abi.encodePacked(orderId, amount, settlementTime, gatewayRef)
        );
        return (computedHash == receipt.receiptHash, receipt.receiptHash);
    }

    /**
     * @notice Compute the receipt hash the same way the contract does (helper for off-chain use).
     * @dev    Backend should call this via eth_call to generate the hash before recording.
     */
    function computeReceiptHash(
        string calldata orderId,
        uint256         amount,
        uint256         settlementTime,
        string calldata gatewayRef
    )
        external
        pure
        returns (bytes32)
    {
        return keccak256(abi.encodePacked(orderId, amount, settlementTime, gatewayRef));
    }

    /**
     * @notice Get the current status of an order.
     * @param orderId The unique order/transaction ID.
     */
    function getStatus(string calldata orderId)
        external
        view
        orderExists(orderId)
        returns (PaymentStatus)
    {
        return _receipts[orderId].status;
    }

    // ─── Admin Functions ──────────────────────────────────────────────────────

    /**
     * @notice Authorize or revoke a gateway backend address.
     * @param gateway    Address to authorize (e.g., the Laravel backend wallet).
     * @param authorized True to authorize, false to revoke.
     */
    function setGatewayAuthorization(address gateway, bool authorized)
        external
        onlyOwner
    {
        require(gateway != address(0), "PaymentReceiptRegistry: zero address");
        _authorizedGateways[gateway] = authorized;
        emit GatewayAuthorizationChanged(gateway, authorized);
    }

    /**
     * @notice Check if an address is an authorized gateway.
     */
    function isAuthorizedGateway(address gateway)
        external
        view
        returns (bool)
    {
        return _authorizedGateways[gateway];
    }
}
