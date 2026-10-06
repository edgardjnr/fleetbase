import { helper } from '@ember/component/helper';
import { numeroIfood } from '../utils/entregas-pedido';

// Entregas: número do iFood do pedido (notas "iFood #N" + internal_id N), ou null. Mesma regra das listas e do detalhe.
export default helper(([notas, idInterno]) => numeroIfood(notas, idInterno));
