'use strict';

global.window = {crypto: require('crypto').webcrypto};
require('../assets/js/identifier-processor.js');

(async function () {
  const processor = window.TagVerityIdentifierProcessor;
  const event = await processor.enrichEvent(
    {event_name: 'generate_lead', email: 'raw@example.com', parameters: {first_name: 'Raw', form_name: 'Contact us'}},
    {email_address: ' Test@Example.com ', name: ' ERIC   DE KOCK '},
    {hashed_pii_enabled: true, allowed_hashed_fields: ['email', 'full_name']},
    true,
    'google_ads'
  );

  if (event.email || event.parameters.first_name || event.parameters.form_name !== 'Contact us') {
    throw new Error('Raw identifiers were not removed from the browser event payload.');
  }
  if (!event.user_data.email || !event.user_data.full_name) {
    throw new Error('Allowed identifiers were not normalised and hashed for the browser event payload.');
  }
  if (JSON.stringify(event).includes('Test@Example.com') || JSON.stringify(event).includes('ERIC')) {
    throw new Error('A raw identifier leaked into the browser event payload.');
  }

  console.log('IdentifierProcessor browser tests passed.');
}()).catch(function (error) {
  console.error(error.message);
  process.exit(1);
});
