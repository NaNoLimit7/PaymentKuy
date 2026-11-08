const { ethers, network } = require("hardhat");

/**
 * Deployment Script: PaymentReceiptRegistry
 *
 * Usage:
 *   Local:   npx hardhat run scripts/deploy.js --network localhost
 *   Sepolia: npx hardhat run scripts/deploy.js --network sepolia
 *
 * After Sepolia deployment, verify on Etherscan:
 *   npx hardhat verify --network sepolia <CONTRACT_ADDRESS> <DEPLOYER_ADDRESS>
 */
async function main() {
  const networkName = network.name;
  console.log(`\n🚀 Deploying PaymentReceiptRegistry to: ${networkName}`);
  console.log("─".repeat(55));

  // ── Signers ────────────────────────────────────────────────
  const [deployer] = await ethers.getSigners();
  console.log(`📋 Deployer address : ${deployer.address}`);

  const balance = await ethers.provider.getBalance(deployer.address);
  console.log(`💰 Deployer balance : ${ethers.formatEther(balance)} ETH`);

  if (networkName === "sepolia" && balance === 0n) {
    console.error("\n❌ Deployer has no Sepolia ETH!");
    console.error("   Get free testnet ETH from: https://sepoliafaucet.com");
    process.exit(1);
  }

  // ── Deploy ────────────────────────────────────────────────
  console.log("\n📦 Deploying contract...");
  const Registry = await ethers.getContractFactory("PaymentReceiptRegistry");
  const registry = await Registry.deploy(deployer.address);
  await registry.waitForDeployment();

  const contractAddress = await registry.getAddress();
  console.log(`\n✅ Contract deployed!`);
  console.log(`   Address       : ${contractAddress}`);
  console.log(`   Owner         : ${deployer.address}`);

  // ── Post-deploy Info ──────────────────────────────────────
  if (networkName === "sepolia") {
    console.log(`\n🔗 Etherscan URL:`);
    console.log(`   https://sepolia.etherscan.io/address/${contractAddress}`);

    console.log(`\n📋 Verify contract with:`);
    console.log(`   npx hardhat verify --network sepolia ${contractAddress} ${deployer.address}`);
  }

  // ── Write deployment info to file ─────────────────────────
  const deploymentInfo = {
    network: networkName,
    contractAddress,
    deployer: deployer.address,
    deployedAt: new Date().toISOString(),
    abi: "See artifacts/contracts/PaymentReceiptRegistry.sol/PaymentReceiptRegistry.json",
  };

  const fs = require("fs");
  const path = require("path");
  const outPath = path.join(__dirname, `../deployments/${networkName}.json`);
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(deploymentInfo, null, 2));
  console.log(`\n💾 Deployment info saved to: deployments/${networkName}.json`);

  // ── Quick sanity check ────────────────────────────────────
  console.log(`\n🧪 Sanity check...`);
  const owner = await registry.owner();
  const totalReceipts = await registry.totalReceipts();
  console.log(`   owner()         = ${owner}`);
  console.log(`   totalReceipts() = ${totalReceipts}`);
  console.log(`\n🎉 Deployment complete!\n`);

  return contractAddress;
}

main()
  .then(() => process.exit(0))
  .catch((err) => {
    console.error("\n💥 Deployment failed:", err);
    process.exit(1);
  });
