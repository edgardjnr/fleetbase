<?php

namespace App\Support\Entregas\PrePago;

/** Entregas RestaurantePro: a recarga não pode ser pedida ou cancelada agora (mensagem pt-BR; o controller responde 422). */
class RecargaRecusada extends \RuntimeException
{
}
