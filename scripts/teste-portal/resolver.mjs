// Os utils do portal importam uns aos outros sem extensão (como o build do Ember espera); no Node, o import precisa do .js.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { register } from 'node:module';

register('./resolver-hook.mjs', import.meta.url);
