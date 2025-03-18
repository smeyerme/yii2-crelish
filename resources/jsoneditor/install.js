const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Path to vendor directory
const vendorDir = path.resolve(__dirname, '../../vendor');
const npmAssetDir = path.join(vendorDir, 'npm-asset');

// Check if jsoneditor is already installed
const jsoneditorDir = path.join(npmAssetDir, 'jsoneditor');
if (!fs.existsSync(jsoneditorDir)) {
  console.log('Installing jsoneditor package...');
  
  // Create directory if it doesn't exist
  if (!fs.existsSync(npmAssetDir)) {
    fs.mkdirSync(npmAssetDir, { recursive: true });
  }
  
  // Change to npm-asset directory
  process.chdir(npmAssetDir);
  
  // Install jsoneditor
  try {
    execSync('npm install jsoneditor@9.10.2', { stdio: 'inherit' });
    console.log('jsoneditor installed successfully!');
  } catch (error) {
    console.error('Error installing jsoneditor:', error.message);
    process.exit(1);
  }
} else {
  console.log('jsoneditor is already installed.');
} 