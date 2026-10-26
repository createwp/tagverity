'use strict';

(function () {
  const fields = ['email', 'phone', 'full_name', 'first_name', 'last_name'];
  const aliases = {
    email: 'email', email_address: 'email', emailaddress: 'email',
    phone: 'phone', phone_number: 'phone', phonenumber: 'phone', telephone: 'phone', tel: 'phone',
    name: 'full_name', full_name: 'full_name', fullname: 'full_name',
    firstname: 'first_name', first_name: 'first_name', fname: 'first_name', given_name: 'first_name', givenname: 'first_name',
    lastname: 'last_name', last_name: 'last_name', lname: 'last_name', family_name: 'last_name', familyname: 'last_name'
  };
  const googleDestinations = ['ga4', 'google_ads', 'google_customer_match', 'cm360'];

  function trim(value) {
    return String(value == null ? '' : value).replace(/^\s+|\s+$/gu, '');
  }

  function normalisePhone(value) {
    value = trim(value).replace(/^00/, '+');
    const hasPlus = value.startsWith('+');
    const digits = value.replace(/\D+/g, '');
    return (hasPlus ? '+' : '') + digits;
  }

  function normaliseGoogleEmail(email) {
    const parts = email.split('@');
    if (parts.length !== 2 || ['gmail.com', 'googlemail.com'].indexOf(parts[1]) === -1) return email;
    return parts[0].split('+', 1)[0].replace(/\./g, '') + '@' + parts[1];
  }

  function normalise(field, value, destination) {
    field = aliases[field] || field;
    if (fields.indexOf(field) === -1) throw new Error('unsupported_identifier_field');
    if (field === 'phone') return normalisePhone(value);
    let result = trim(value).toLocaleLowerCase('en-US');
    if (['full_name', 'first_name', 'last_name'].indexOf(field) !== -1) {
      result = result.replace(/\s+/gu, ' ');
    } else if (field === 'email' && googleDestinations.indexOf(destination || 'common') !== -1) {
      result = normaliseGoogleEmail(result);
    }
    return result;
  }

  function isValid(field, value) {
    if (!value || value.length > 320) return false;
    if (field === 'email') return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    if (field === 'phone') return /^\+[1-9][0-9]{7,14}$/.test(value);
    return true;
  }

  async function hash(value) {
    if (!window.crypto || !window.crypto.subtle) throw new Error('sha256_unavailable');
    const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
    return Array.from(new Uint8Array(digest)).map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
  }

  async function process(field, value, destination) {
    const canonicalField = aliases[field] || field;
    const normalised = normalise(canonicalField, value, destination || 'common');
    if (!isValid(canonicalField, normalised)) throw new Error(canonicalField === 'phone' ? 'e164_required' : 'invalid_value');
    return {field: canonicalField, normalised: normalised, hashed: await hash(normalised), normalisation_version: '1.1.0'};
  }

  function canonicalValues(values) {
    const canonical = {};
    Object.keys(values || {}).forEach(function (field) {
      const target = aliases[field.toLowerCase().trim()];
      if (!target) return;
      if (Object.prototype.hasOwnProperty.call(canonical, target) && field.toLowerCase().trim() !== target) return;
      const value = values[field];
      if (['string', 'number', 'boolean'].indexOf(typeof value) === -1) return;
      canonical[target] = String(value);
    });
    return canonical;
  }

  async function processAllowed(values, allowedFields, destination) {
    const canonical = canonicalValues(values);
    const allowed = Array.from(new Set((allowedFields || []).map(function (field) { return aliases[field] || field; })))
      .filter(function (field) { return fields.indexOf(field) !== -1; });
    const hashed = {};
    const errors = {};
    for (const field of allowed) {
      if (!Object.prototype.hasOwnProperty.call(canonical, field)) continue;
      try {
        const result = await process(field, canonical[field], destination || 'common');
        hashed[field] = result.hashed;
      } catch (error) {
        errors[field] = error.message;
      }
    }
    return {hashed: hashed, errors: errors, normalisation_version: '1.1.0'};
  }

  function stripRawIdentifiers(value) {
    if (Array.isArray(value)) return value.map(stripRawIdentifiers);
    if (!value || typeof value !== 'object') return value;
    const clean = {};
    Object.keys(value).forEach(function (key) {
      const normalisedKey = key.toLowerCase().trim();
      if (normalisedKey !== 'name' && (aliases[normalisedKey] || fields.indexOf(normalisedKey) !== -1)) return;
      clean[key] = stripRawIdentifiers(value[key]);
    });
    return clean;
  }

  async function enrichEvent(event, rawIdentifiers, privacy, consentGranted, destination) {
    const enriched = stripRawIdentifiers(event || {});
    enriched.user_data = {};
    enriched.identifier_processing = {normalisation_version: '1.1.0', consent_granted: !!consentGranted, errors: {}};
    if (!consentGranted || !privacy || !privacy.hashed_pii_enabled) return enriched;
    const processed = await processAllowed(rawIdentifiers, privacy.allowed_hashed_fields, destination || 'common');
    enriched.user_data = processed.hashed;
    enriched.identifier_processing.errors = processed.errors;
    return enriched;
  }

  window.TagVerityIdentifierProcessor = {
    normalise: normalise,
    isValid: isValid,
    hash: hash,
    process: process,
    processAllowed: processAllowed,
    enrichEvent: enrichEvent,
    version: '1.1.0'
  };
}());
