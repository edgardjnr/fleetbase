#!/usr/bin/env node
// Checagem estática das views de e-mail do Entregas RestaurantePro (api/resources/views/vendor): existem, não têm texto
// em inglês das views originais nem "Fleetbase", e as views de markdown não têm linha recuada (vira bloco de código).
// Uso, da raiz do repo: node scripts/teste-emails-views.mjs
import fs from 'node:fs';
import path from 'node:path';

const base = 'api/resources/views/vendor';
const views = {
    'mail/html/message.blade.php': false,
    'mail/html/codigo.blade.php': false,
    'mail/html/dados.blade.php': false,
    'mail/text/dados.blade.php': false,
    'mail/html/themes/default.css': false,
    'mail/text/message.blade.php': false,
    'mail/text/codigo.blade.php': false,
    'notifications/email.blade.php': true,
    'fleetbase/layout/mail.blade.php': true,
    'fleetbase/mail/verification.blade.php': true,
    'fleetbase/mail/user-credentials.blade.php': true,
    'fleetbase/mail/test.blade.php': true,
};
const proibidos = ['Fleetbase', 'Regards', 'Hello', 'Whoops', 'All rights reserved', 'All Rights Reserved', 'Good Morning', 'Verify Email', "If you're having trouble", 'Your verification code', 'Your login credentials'];
// arquivo => textos que têm de aparecer
const obrigatorios = {
    'mail/html/message.blade.php': ['MarcaDoEmail::logo()', 'Entregas RestaurantePro · e-mail automático, não responda.'],
    'mail/text/message.blade.php': ['Entregas RestaurantePro · e-mail automático, não responda.'],
    'notifications/email.blade.php': ['Se o botão', 'align="left"'],
    'fleetbase/mail/verification.blade.php': ['CodigosPorEmail::textos', '<x-mail::codigo>'],
    'fleetbase/mail/user-credentials.blade.php': ['Seus dados de acesso', '$plaintextPassword', '<x-mail::dados'],
    'fleetbase/mail/test.blade.php': ['O envio de e-mail está funcionando'],
};

let falhas = 0;
const confere = (ok, descricao) => {
    if (!ok) falhas++;
    console.log(`${ok ? 'PASSA' : 'FALHA'} ${descricao}`);
};

for (const [arquivo, markdown] of Object.entries(views)) {
    const caminho = path.join(base, arquivo);
    const existe = fs.existsSync(caminho);
    confere(existe, `${arquivo} existe`);
    if (!existe) continue;
    const texto = fs.readFileSync(caminho, 'utf8');
    for (const p of proibidos) confere(!texto.includes(p), `${arquivo} sem "${p}"`);
    for (const o of obrigatorios[arquivo] ?? []) confere(texto.includes(o), `${arquivo} contém "${o}"`);
    if (markdown) {
        const recuadas = texto.split(/\r?\n/).filter((l) => /^( {4}|\t)/.test(l) && !/^\s*(\{\{--|@php|@endphp|\$)/.test(l));
        confere(recuadas.length === 0, `${arquivo} sem linha recuada (${recuadas.length})`);
    }
}
console.log(`\nFALHAS: ${falhas}`);
process.exit(falhas ? 1 : 0);
