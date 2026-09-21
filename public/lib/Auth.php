<?php

declare(strict_types=1);

/**
 * Sessão do utilizador: quem está ligado, tokens CSRF, e o "exigir login".
 */
final class Auth
{
    public function __construct(private readonly Database $db)
    {
    }

    public function utilizadorAtual(): ?array
    {
        if (!isset($_SESSION['utilizador_id'])) {
            return null;
        }

        return $this->db->encontrarUtilizadorPorId((int) $_SESSION['utilizador_id']);
    }

    public function autenticado(): bool
    {
        return isset($_SESSION['utilizador_id']);
    }

    public function iniciarSessao(int $utilizadorId): void
    {
        session_regenerate_id(true);
        $_SESSION['utilizador_id'] = $utilizadorId;
    }

    public function terminarSessao(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function tokenCsrf(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function validarCsrf(?string $token): bool
    {
        return $token !== null
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Redireciona para o login se não houver sessão ativa.
     */
    public function exigirLogin(string $destinoAposLogin = '/'): void
    {
        if (!$this->autenticado()) {
            header('Location: /login.php?next=' . urlencode($destinoAposLogin));
            exit;
        }
    }

    /**
     * Garante que um destino de redireção é um caminho local (nunca outro site).
     */
    public static function caminhoSeguro(?string $caminho, string $padrao = '/'): string
    {
        if (!is_string($caminho) || $caminho === '' || $caminho[0] !== '/' || str_starts_with($caminho, '//')) {
            return $padrao;
        }

        return $caminho;
    }
}
