/**
 * Secrets Manager - Secure Storage and Rotation
 * 
 * Provides secure handling of sensitive data with encryption,
 * rotation policies, and audit trails.
 */

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

class SecretsManager {
  constructor() {
    this.secrets = new Map();
    this.encryptedSecrets = new Map();
    this.rotationHistory = new Map();
    this.auditLogs = [];
    this.encryptionKey = this.getOrCreateEncryptionKey();
    this.secretsDirectory = path.join(__dirname, '..', '..', '..', 'secrets');
    this.ensureSecretsDirectory();
  }

  /**
   * Ensure secrets directory exists
   */
  ensureSecretsDirectory() {
    if (!fs.existsSync(this.secretsDirectory)) {
      fs.mkdirSync(this.secretsDirectory, { recursive: true, mode: 0o700 });
    }
  }

  /**
   * Get or create encryption key
   */
  getOrCreateEncryptionKey() {
    const keyFile = path.join(this.secretsDirectory, '.encryption-key');
    
    if (fs.existsSync(keyFile)) {
      const key = fs.readFileSync(keyFile, 'utf8').trim();
      if (key.length === 64) { // 32 bytes hex-encoded
        return key;
      }
    }
    
    // Generate new encryption key
    const newKey = crypto.randomBytes(32).toString('hex');
    fs.writeFileSync(keyFile, newKey, { mode: 0o600 });
    return newKey;
  }

  /**
   * Encrypt data using AES-256-GCM
   */
  encrypt(data, context = '') {
    const iv = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv('aes-256-gcm', Buffer.from(this.encryptionKey, 'hex'), iv);
    
    let encrypted = cipher.update(data, 'utf8', 'hex');
    encrypted += cipher.final('hex');
    
    const authTag = cipher.getAuthTag();
    
    return {
      iv: iv.toString('hex'),
      encrypted,
      authTag: authTag.toString('hex'),
      context,
      algorithm: 'aes-256-gcm',
      version: '1.0',
      timestamp: new Date().toISOString()
    };
  }

  /**
   * Decrypt data using AES-256-GCM
   */
  decrypt(encryptedData) {
    const { iv, encrypted, authTag, context, algorithm, version } = encryptedData;
    
    if (algorithm !== 'aes-256-gcm') {
      throw new Error(`Unsupported encryption algorithm: ${algorithm}`);
    }
    
    const decipher = crypto.createDecipheriv(
      'aes-256-gcm',
      Buffer.from(this.encryptionKey, 'hex'),
      Buffer.from(iv, 'hex')
    );
    
    decipher.setAuthTag(Buffer.from(authTag, 'hex'));
    
    let decrypted = decipher.update(encrypted, 'hex', 'utf8');
    decrypted += decipher.final('utf8');
    
    return decrypted;
  }

  /**
   * Store a secret securely
   */
  storeSecret(name, secret, options = {}) {
    const {
      description = '',
      tags = [],
      expiresAt = null,
      autoRotate = false,
      rotationInterval = 30 * 24 * 60 * 60 * 1000, // 30 days
      rotationPolicy = 'rotate',
      allowedIPs = [],
      allowedUsers = [],
      audit = true
    } = options;

    // Validate secret
    if (!secret || typeof secret !== 'string') {
      throw new Error('Secret must be a non-empty string');
    }

    // Check if secret already exists and handle rotation
    const existingSecret = this.encryptedSecrets.get(name);
    if (existingSecret) {
      if (autoRotate && this.shouldRotate(existingSecret, rotationInterval)) {
        this.rotateSecret(name, rotationPolicy);
      }
    }

    // Create secret metadata
    const secretMetadata = {
      name,
      description,
      tags,
      expiresAt,
      autoRotate,
      rotationInterval,
      rotationPolicy,
      allowedIPs,
      allowedUsers,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      version: existingSecret ? existingMetadata.version + 1 : 1,
      createdBy: options.createdBy || 'system'
    };

    // Encrypt the secret
    const encryptedData = this.encrypt(secret, name);
    this.encryptedSecrets.set(name, encryptedData);
    this.secrets.set(name, secretMetadata);

    // Log the action
    if (audit) {
      this.auditLog('store', name, {
        metadata: secretMetadata,
        rotation: autoRotate,
        encrypted: true
      });
    }

    // Save to file for persistence
    this.saveSecretsToFile();

    return secretMetadata;
  }

  /**
   * Retrieve a secret
   */
  retrieveSecret(name, options = {}) {
    const { audit = true, context = '' } = options;

    if (!this.encryptedSecrets.has(name)) {
      throw new Error(`Secret '${name}' not found`);
    }

    const encryptedData = this.encryptedSecrets.get(name);
    const metadata = this.secrets.get(name);

    // Check expiration
    if (metadata.expiresAt && new Date(metadata.expiresAt) < new Date()) {
      throw new Error(`Secret '${name}' has expired`);
    }

    // Check IP restrictions
    if (metadata.allowedIPs.length > 0) {
      const clientIP = options.clientIP || 'unknown';
      if (!metadata.allowedIPs.includes(clientIP)) {
        throw new Error(`Access to secret '${name}' not allowed from IP: ${clientIP}`);
      }
    }

    // Check user restrictions
    if (metadata.allowedUsers.length > 0) {
      const userId = options.userId || 'unknown';
      if (!metadata.allowedUsers.includes(userId)) {
        throw new Error(`Access to secret '${name}' not allowed for user: ${userId}`);
      }
    }

    // Decrypt the secret
    const secret = this.decrypt(encryptedData);

    // Log the retrieval
    if (audit) {
      this.auditLog('retrieve', name, {
        metadata,
        context,
        clientIP: options.clientIP
      });
    }

    return {
      secret,
      metadata
    };
  }

  /**
   * Check if secret should be rotated
   */
  shouldRotate(secretData, rotationInterval) {
    const createdAt = new Date(secretData.timestamp);
    const now = new Date();
    return (now - createdAt) > rotationInterval;
  }

  /**
   * Rotate a secret
   */
  rotateSecret(name, policy = 'rotate') {
    if (!this.encryptedSecrets.has(name)) {
      throw new Error(`Secret '${name}' not found`);
    }

    const encryptedData = this.encryptedSecrets.get(name);
    const metadata = this.secrets.get(name);

    switch (policy) {
      case 'rotate':
        // Generate new secret
        const newSecret = crypto.randomBytes(32).toString('hex');
        this.storeSecret(name, newSecret, {
          description: metadata.description,
          tags: metadata.tags,
          autoRotate: metadata.autoRotate,
          rotationInterval: metadata.rotationInterval,
          rotationPolicy: metadata.rotationPolicy,
          allowedIPs: metadata.allowedIPs,
          allowedUsers: metadata.allowedUsers,
          audit: false
        });
        break;

      case 'revoke':
        this.deleteSecret(name);
        break;

      default:
        throw new Error(`Unknown rotation policy: ${policy}`);
    }

    // Log rotation
    this.auditLog('rotate', name, {
      policy,
      oldVersion: metadata.version,
      newVersion: this.secrets.get(name).version
    });
  }

  /**
   * Delete a secret
   */
  deleteSecret(name) {
    if (!this.encryptedSecrets.has(name)) {
      throw new Error(`Secret '${name}' not found`);
    }

    const metadata = this.secrets.get(name);
    this.encryptedSecrets.delete(name);
    this.secrets.delete(name);

    this.auditLog('delete', name, { metadata });
    this.saveSecretsToFile();

    return true;
  }

  /**
   * List all secrets (metadata only)
   */
  listSecrets() {
    return Array.from(this.secrets.values()).map(metadata => ({
      name: metadata.name,
      description: metadata.description,
      tags: metadata.tags,
      expiresAt: metadata.expiresAt,
      autoRotate: metadata.autoRotate,
      rotationInterval: metadata.rotationInterval,
      rotationPolicy: metadata.rotationPolicy,
      allowedIPs: metadata.allowedIPs,
      allowedUsers: metadata.allowedUsers,
      createdAt: metadata.createdAt,
      updatedAt: metadata.updatedAt,
      version: metadata.version,
      createdBy: metadata.createdBy
    }));
  }

  /**
   * Get secret metadata
   */
  getSecretMetadata(name) {
    const metadata = this.secrets.get(name);
    if (!metadata) {
      throw new Error(`Secret '${name}' not found`);
    }
    return metadata;
  }

  /**
   * Update secret metadata
   */
  updateSecretMetadata(name, updates = {}) {
    if (!this.secrets.has(name)) {
      throw new Error(`Secret '${name}' not found`);
    }

    const metadata = this.secrets.get(name);
    Object.assign(metadata, updates, { updatedAt: new Date().toISOString() });
    this.secrets.set(name, metadata);

    this.auditLog('update_metadata', name, { updates });
    this.saveSecretsToFile();

    return metadata;
  }

  /**
   * Audit logging
   */
  auditLog(action, secretName, details = {}) {
    const auditEntry = {
      timestamp: new Date().toISOString(),
      action,
      secretName,
      details,
      userId: details.userId || 'system',
      clientIP: details.clientIP || 'unknown'
    };

    this.auditLogs.push(auditEntry);

    // Keep only last 1000 audit logs
    if (this.auditLogs.length > 1000) {
      this.auditLogs = this.auditLogs.slice(-1000);
    }

    console.log(`[SECRETS] ${action} ${secretName} - ${JSON.stringify(details)}`);
  }

  /**
   * Get audit logs
   */
  getAuditLogs(limit = 100) {
    return this.auditLogs.slice(-limit);
  }

  /**
   * Save secrets to file
   */
  saveSecretsToFile() {
    try {
      const data = {
        encryptedSecrets: Array.from(this.encryptedSecrets.entries()),
        secrets: Array.from(this.secrets.entries()),
        version: '1.0',
        timestamp: new Date().toISOString()
      };

      const tempFile = path.join(this.secretsDirectory, '.secrets.tmp');
      const finalFile = path.join(this.secretsDirectory, '.secrets');

      fs.writeFileSync(tempFile, JSON.stringify(data, null, 2), { mode: 0o600 });
      
      // Atomic write
      fs.renameSync(tempFile, finalFile);

    } catch (error) {
      console.error('Failed to save secrets to file:', error);
      throw error;
    }
  }

  /**
   * Load secrets from file
   */
  loadSecretsFromFile() {
    try {
      const secretsFile = path.join(this.secretsDirectory, '.secrets');
      
      if (fs.existsSync(secretsFile)) {
        const data = JSON.parse(fs.readFileSync(secretsFile, 'utf8'));
        
        if (data.encryptedSecrets) {
          this.encryptedSecrets = new Map(data.encryptedSecrets);
        }
        
        if (data.secrets) {
          this.secrets = new Map(data.secrets);
        }
      }
    } catch (error) {
      console.error('Failed to load secrets from file:', error);
    }
  }

  /**
   * Clean up expired secrets
   */
  cleanupExpiredSecrets() {
    const now = new Date();
    let cleanedCount = 0;

    for (const [name, metadata] of this.secrets.entries()) {
      if (metadata.expiresAt && new Date(metadata.expiresAt) < now) {
        this.deleteSecret(name);
        cleanedCount++;
      }
    }

    if (cleanedCount > 0) {
      this.auditLog('cleanup_expired', 'all', { cleanedCount });
    }

    return cleanedCount;
  }

  /**
   * Export secrets (for backup)
   */
  exportSecrets(includeEncrypted = false) {
    const data = {
      secrets: Array.from(this.secrets.entries()),
      auditLogs: this.auditLogs,
      version: '1.0',
      exportedAt: new Date().toISOString(),
      encryptionKey: includeEncrypted ? this.encryptionKey : null
    };

    if (!includeEncrypted) {
      delete data.encryptionKey;
    }

    return data;
  }

  /**
   * Import secrets (from backup)
   */
  importSecrets(data, options = {}) {
    const { validateOnly = false, overwrite = false } = options;

    if (data.version !== '1.0') {
      throw new Error('Unsupported secrets file version');
    }

    if (validateOnly) {
      return { valid: true, count: data.secrets?.length || 0 };
    }

    if (!overwrite && this.secrets.size > 0) {
      throw new Error('Secrets already exist. Use overwrite=true to replace.');
    }

    // Import secrets
    if (data.secrets) {
      this.secrets = new Map(data.secrets);
    }

    // Import encrypted secrets
    if (data.encryptedSecrets) {
      this.encryptedSecrets = new Map(data.encryptedSecrets);
    }

    // Import audit logs
    if (data.auditLogs) {
      this.auditLogs = [...this.auditLogs, ...data.auditLogs];
    }

    this.saveSecretsToFile();
    return { imported: true, count: data.secrets?.length || 0 };
  }

  /**
   * Generate secure random secret
   */
  generateSecret(length = 32) {
    return crypto.randomBytes(length).toString('hex');
  }

  /**
   * Check if secret contains weak patterns
   */
  checkSecretStrength(secret) {
    const checks = {
      length: secret.length >= 16,
      uppercase: /[A-Z]/.test(secret),
      lowercase: /[a-z]/.test(secret),
      numbers: /\d/.test(secret),
      special: /[!@#$%^&*(),.?":{}|<>]/.test(secret)
    };

    const score = Object.values(checks).filter(Boolean).length;
    const isStrong = score >= 4;

    return {
      isStrong,
      score: score / 5,
      checks,
      recommendations: this.getRecommendations(checks)
    };
  }

  /**
   * Get recommendations for weak secrets
   */
  getRecommendations(checks) {
    const recommendations = [];

    if (!checks.length) recommendations.push('Use at least 16 characters');
    if (!checks.uppercase) recommendations.push('Include uppercase letters');
    if (!checks.lowercase) recommendations.push('Include lowercase letters');
    if (!checks.numbers) recommendations.push('Include numbers');
    if (!checks.special) recommendations.push('Include special characters');

    return recommendations;
  }
}

module.exports = SecretsManager;