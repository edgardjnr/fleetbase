/**
 * Entregas: paleta do console no lugar do cinza azulado e do azul do Fleetbase (decisão de 2026-10-26: "o azul não
 * combina com a paleta", no tema escuro). Os tons saem dos tokens --pi-* (console/app/styles/painel-inicial.css),
 * convertidos de OKLCH para hex, porque o Tailwind 3 só faz opacidade (bg-gray-900/50, bg-opacity) com hex/rgb.
 *
 * - gray: areia (matiz 60-75, croma baixo); 700/800/900/950 = linha/cartão-2/cartão/fundo escuros do console.
 * - sky: a cor de marca do Fleetbase (btn-primary, foco, ícones) vira o laranja; 500 = --pi-laranja.
 * - blue: as pontas (fundos de selo e de aviso) ficam areia com um toque de laranja; o meio (links, abas, caixas de
 *   seleção) fica laranja; 600 escurecido para ler no fundo claro.
 * - night, nightsky, moregray: os escuros azulados do tema escuro antigo.
 *
 * Usado por packages/ember-ui/tailwind.config.js (o que compila o CSS do console) e console/tailwind.config.js, e pelo
 * scripts/paleta-entregas.mjs, que troca as mesmas cores fixas nos CSS das engines e do ember-ui. Mudou um tom aqui?
 * Rode o script de novo (o cabeçalho dele explica).
 */
const gray = {
    50: '#fcfaf7',
    100: '#f6f3ee',
    200: '#eae5df',
    300: '#d9d3cc',
    400: '#a7a099',
    500: '#79716c',
    600: '#5a524d',
    700: '#3b342e',
    800: '#29231d',
    900: '#1f1915',
    950: '#16100c',
};

const sky = {
    50: '#fff5e8',
    100: '#ffebd2',
    200: '#ffd5a5',
    300: '#ffba72',
    400: '#fca444',
    500: '#f38f17',
    600: '#e47600',
    700: '#bd5b00',
    800: '#883c00',
    900: '#542705',
    950: '#371802',
};

const blue = {
    50: '#fdf6ec',
    100: '#feebd6',
    200: '#fed6ad',
    300: '#ffbf7e',
    400: '#f8a650',
    500: '#eb881f',
    600: '#c26201',
    700: '#a14f09',
    800: '#733d18',
    900: '#4a2a12',
    950: '#321b0b',
};

const nightsky = {
    100: '#362c24',
    200: '#28201a',
    300: '#1a1511',
    400: '#0e0a07',
};

const night = {
    801: '#26201b',
    802: '#26201b',
    803: '#241e19',
    804: '#241e19',
    805: '#241e19',
    901: '#1d1713',
    902: '#1d1713',
    903: '#1a1511',
    904: '#1a1511',
    905: '#1a1511',
};

const moregray = {
    750: '#332c27',
    850: '#26201b',
};

/** Cores originais (Tailwind 3 e Fleetbase) → paleta do console, para as cores fixas nos CSS (scripts/paleta-entregas.mjs). */
const trocas = {
    // gray do Tailwind
    f9fafb: gray[50],
    f3f4f6: gray[100],
    e5e7eb: gray[200],
    d1d5db: gray[300],
    '9ca3af': gray[400],
    '6b7280': gray[500],
    '4b5563': gray[600],
    374151: gray[700],
    '1f2937': gray[800],
    111827: gray[900],
    '030712': gray[950],
    // sky do Fleetbase
    e6f0fb: sky[100],
    bad5f5: sky[200],
    '8dbbef': sky[300],
    '61a0e8': sky[400],
    '3485e2': sky[500],
    '1c6cc7': sky[600],
    '16539a': sky[700],
    '103b6d': sky[800],
    '092341': sky[900],
    // blue do Tailwind
    eff6ff: blue[50],
    dbeafe: blue[100],
    bfdbfe: blue[200],
    '93c5fd': blue[300],
    '60a5fa': blue[400],
    '3b82f6': blue[500],
    '2563eb': blue[600],
    '1d4ed8': blue[700],
    '1e40af': blue[800],
    '1e3a8a': blue[900],
    172554: blue[950],
    // night, nightsky e moregray do Fleetbase, e o divisor do tema escuro
    '222c3c': night[801],
    '202a3a': night[802],
    '1d2737': night[803],
    '1b2535': night[804],
    182232: night[805],
    '131b2b': night[901],
    111929: night[902],
    '0e1626': night[903],
    '0c1424': night[904],
    '091121': night[905],
    '0d2f57': nightsky[100],
    '092340': nightsky[200],
    '06172a': nightsky[300],
    '030b14': nightsky[400],
    283345: moregray[750],
    '212a38': moregray[850],
    '2b323e': gray[700],
};

module.exports = { colors: { gray, sky, blue, nightsky, night, moregray }, trocas };
