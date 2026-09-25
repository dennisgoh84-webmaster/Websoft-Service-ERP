/**
 * Websoft Incidents -- Gmail add-on (docs/outlook-addin.md).
 *
 * The Gmail twin of the Outlook Add-in: on an open email, "Log as
 * Incident" and "Convert to Job Order", through the same Websoft
 * endpoints. Runs in Google Apps Script; Maintenance -> Email Add-ins
 * downloads this file with BASE_URL already filled in.
 *
 * Sign-in is a one-time connect code from the Websoft web app, never a
 * password: a Gmail add-on panel has no password box, so a password
 * would show on screen as it is typed.
 */
var BASE_URL = '{{BASE_URL}}';
var TOKEN_KEY = 'websoft_token';

// ---- Websoft API -------------------------------------------------------

function api_(path, payload, token) {
  var headers = { Accept: 'application/json' };
  if (token) headers.Authorization = 'Bearer ' + token;
  var res = UrlFetchApp.fetch(BASE_URL + '/api' + path, {
    method: 'post',
    contentType: 'application/json',
    headers: headers,
    payload: JSON.stringify(payload || {}),
    muteHttpExceptions: true,
  });
  var status = res.getResponseCode();
  var data = {};
  try {
    data = JSON.parse(res.getContentText() || '{}');
  } catch (e) {
    /* not JSON */
  }
  if (status >= 400) {
    var err = new Error(data.detail || data.message || 'Websoft replied with an error (' + status + ').');
    err.status = status;
    throw err;
  }
  return data;
}

function getToken_() {
  return PropertiesService.getUserProperties().getProperty(TOKEN_KEY);
}

function setToken_(token) {
  var props = PropertiesService.getUserProperties();
  if (token) props.setProperty(TOKEN_KEY, token);
  else props.deleteProperty(TOKEN_KEY);
}

// ---- The open email ----------------------------------------------------

function openMessage_(e, messageId) {
  if (e && e.gmail && e.gmail.accessToken) GmailApp.setCurrentMessageAccessToken(e.gmail.accessToken);
  var id = messageId || (e && e.gmail && e.gmail.messageId);
  return id ? GmailApp.getMessageById(id) : null;
}

// "Kim Tan <kim@example.sg>" -> name and address.
function parseFrom_(from) {
  var m = /^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/.exec(from || '');
  return m ? { name: m[1].trim(), email: m[2].trim() } : { name: '', email: (from || '').trim() };
}

function emailPayload_(message) {
  var from = parseFrom_(message.getFrom());
  return {
    sender_name: from.name,
    sender_email: from.email,
    subject: message.getSubject() || '(no subject)',
    body: message.getPlainBody() || '',
  };
}

// ---- Cards -------------------------------------------------------------

function header_() {
  return CardService.newCardHeader().setTitle('Websoft Incidents').setImageUrl(BASE_URL + '/outlook-addin/assets/icon-80.png');
}

// Card text is HTML: anything from the email or the server is escaped.
function esc_(text) {
  return String(text == null ? '' : text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function errorText_(message) {
  return CardService.newTextParagraph().setText('<font color="#c0362c">' + esc_(message) + '</font>');
}

function connectCard_(messageId, error) {
  var section = CardService.newCardSection()
    .addWidget(
      CardService.newTextParagraph().setText(
        'Connect this add-on to Websoft once. Click <b>Get a code</b>, sign in to Websoft if asked, then type the 8-character code here.',
      ),
    )
    .addWidget(
      CardService.newTextButton()
        .setText('Get a code')
        .setOpenLink(CardService.newOpenLink().setUrl(BASE_URL + '/connect-addin').setOpenAs(CardService.OpenAs.FULL_SIZE)),
    )
    .addWidget(CardService.newTextInput().setFieldName('code').setTitle('Connect code').setHint('e.g. ABCD-2345'))
    .addWidget(
      CardService.newTextButton()
        .setText('Connect')
        .setTextButtonStyle(CardService.TextButtonStyle.FILLED)
        .setOnClickAction(CardService.newAction().setFunctionName('onConnect').setParameters({ messageId: messageId || '' })),
    );
  if (error) section.addWidget(errorText_(error));
  return CardService.newCardBuilder().setHeader(header_()).addSection(section).build();
}

function emailCard_(message, result, error) {
  var p = emailPayload_(message);
  var params = { messageId: message.getId() };
  var section = CardService.newCardSection()
    .addWidget(CardService.newDecoratedText().setTopLabel('From').setText(esc_(p.sender_name ? p.sender_name + ' <' + p.sender_email + '>' : p.sender_email)).setWrapText(true))
    .addWidget(CardService.newDecoratedText().setTopLabel('Subject').setText(esc_(p.subject)).setWrapText(true))
    .addWidget(
      CardService.newButtonSet()
        .addButton(
          CardService.newTextButton()
            .setText('Log as Incident')
            .setTextButtonStyle(CardService.TextButtonStyle.FILLED)
            .setOnClickAction(CardService.newAction().setFunctionName('onLogIncident').setParameters(params)),
        )
        .addButton(
          CardService.newTextButton()
            .setText('Convert to Job Order')
            .setOnClickAction(CardService.newAction().setFunctionName('onConvertToJobOrder').setParameters(params)),
        ),
    );
  if (error) section.addWidget(errorText_(error));
  var card = CardService.newCardBuilder().setHeader(header_()).addSection(section);
  if (result) card.addSection(CardService.newCardSection().setHeader('Done').addWidget(CardService.newTextParagraph().setText(result)));
  card.addSection(
    CardService.newCardSection().addWidget(
      CardService.newTextButton().setText('Sign out').setOnClickAction(CardService.newAction().setFunctionName('onSignOut').setParameters(params)),
    ),
  );
  return card.build();
}

function homeCard_() {
  return CardService.newCardBuilder()
    .setHeader(header_())
    .addSection(CardService.newCardSection().addWidget(CardService.newTextParagraph().setText('Connected. Open an email to log it as an Incident or a Job Order.')))
    .build();
}

function update_(card) {
  return CardService.newActionResponseBuilder().setNavigation(CardService.newNavigation().updateCard(card)).build();
}

function formValue_(e, name) {
  var inputs = e && e.commonEventObject && e.commonEventObject.formInputs;
  if (inputs && inputs[name] && inputs[name].stringInputs) return inputs[name].stringInputs.value[0] || '';
  return (e && e.formInput && e.formInput[name]) || '';
}

function param_(e, name) {
  return (e && e.commonEventObject && e.commonEventObject.parameters && e.commonEventObject.parameters[name]) || (e && e.parameters && e.parameters[name]) || '';
}

// ---- Triggers and buttons ----------------------------------------------

function onHomepage(e) {
  return getToken_() ? homeCard_() : connectCard_('', null);
}

function onGmailMessageOpen(e) {
  if (!getToken_()) return connectCard_(e.gmail.messageId, null);
  return emailCard_(openMessage_(e), null, null);
}

function onConnect(e) {
  var messageId = param_(e, 'messageId');
  var code = formValue_(e, 'code').trim();
  if (!code) return update_(connectCard_(messageId, 'Type the code from Websoft first.'));
  try {
    var data = api_('/auth/addin-connect', { code: code });
    if (data.ai_data_consent_required) {
      return update_(connectCard_(messageId, 'Please sign in to Websoft in your browser once to accept the PDPA declaration, then get a new code.'));
    }
    setToken_(data.access_token);
  } catch (err) {
    return update_(connectCard_(messageId, err.message));
  }
  return update_(messageId ? emailCard_(openMessage_(e, messageId), null, null) : homeCard_());
}

function onSignOut(e) {
  setToken_(null);
  return update_(connectCard_(param_(e, 'messageId'), null));
}

// An expired session sends the user back to connect rather than failing every click.
function act_(e, path, describe) {
  var messageId = param_(e, 'messageId');
  var message = openMessage_(e, messageId);
  try {
    return update_(emailCard_(message, describe(api_(path, emailPayload_(message), getToken_())), null));
  } catch (err) {
    if (err.status === 401) {
      setToken_(null);
      return update_(connectCard_(messageId, 'Your Websoft session has ended. Get a new code to connect again.'));
    }
    return update_(emailCard_(message, null, err.message));
  }
}

function ackLine_(data) {
  return data.acknowledgement_sent ? '<br>The sender has been sent an acknowledgement.' : '<br>No acknowledgement was sent to the sender.';
}

function onLogIncident(e) {
  return act_(e, '/incidents/from-email', function (incident) {
    return 'Logged as Incident <b>' + esc_(incident.incident_number) + '</b>.' + ackLine_(incident);
  });
}

function onConvertToJobOrder(e) {
  return act_(e, '/incidents/from-email/convert-to-job-order', function (r) {
    if (r.job_order_created) {
      return 'Job Order <b>' + esc_(r.job_order_number) + '</b> created from Incident ' + esc_(r.incident.incident_number) + '.' + ackLine_(r);
    }
    return 'No Job Order created: logged as Incident <b>' + esc_(r.incident.incident_number) + '</b> instead.<br>Reason: ' + esc_(r.fallback_reason) + ackLine_(r);
  });
}
