

export interface ResultadoViabilidade {
    sucesso: boolean;
    atende?: boolean;
    motivo?: string;
    mensagem: string;
    endereco_encontrado?: string;
    endereco_partes?: Record<string, string>;
    distancia_m?: number;
}

export function mascaraCep(valorAtual: string): string {
    const digitos = valorAtual.replace(/\D/g, "").slice(0, 8);
    return digitos.length > 5 ? `${digitos.slice(0, 5)}-${digitos.slice(5)}` : digitos;
}

export async function consultarViaCep(cepDigitos: string): Promise<{ erro?: boolean; logradouro?: string; bairro?: string; localidade?: string; uf?: string }> {
    const resposta = await fetch(`https://viacep.com.br/ws/${cepDigitos}/json/`);
    return resposta.json();
}

export async function consultarViabilidade(cep: string, numero: string): Promise<ResultadoViabilidade> {
    const resposta = await fetch("/api/viabilidade/consultar.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ cep, numero }),
    });
    return resposta.json();
}

export function irParaContratar(cep: string, numero: string): void {
    window.location.href = `/contratar?cep=${encodeURIComponent(cep)}&numero=${encodeURIComponent(numero)}`;
}

export const WHATSAPP_COMERCIAL = "https://wa.me/5527995057736";
