// Lógica compartilhada de consulta de viabilidade (CEP + número), usada tanto
// pela busca da home (hero) quanto pela barra flutuante que aparece em
// qualquer página do site. Fica num arquivo só pra não duplicar a mesma
// regra em dois lugares e correr o risco de um dia ficarem diferentes.

export interface ResultadoViabilidade {
    sucesso: boolean;
    atende?: boolean;
    motivo?: string;
    mensagem: string;
    endereco_encontrado?: string;
    endereco_partes?: Record<string, string>;
    distancia_m?: number;
}

// Formata o que a pessoa digita no padrão "00000-000" enquanto ela digita.
export function mascaraCep(valorAtual: string): string {
    const digitos = valorAtual.replace(/\D/g, "").slice(0, 8);
    return digitos.length > 5 ? `${digitos.slice(0, 5)}-${digitos.slice(5)}` : digitos;
}

// Confirmação "ao vivo" do endereço assim que os 8 números do CEP são
// digitados — direto do navegador, sem precisar clicar em nada.
export async function consultarViaCep(cepDigitos: string): Promise<{ erro?: boolean; logradouro?: string; bairro?: string; localidade?: string; uf?: string }> {
    const resposta = await fetch(`https://viacep.com.br/ws/${cepDigitos}/json/`);
    return resposta.json();
}

// Consulta de verdade: manda CEP + número pro backend, que geocodifica e
// compara com a CTO (caixinha de fibra) mais próxima cadastrada no SGP.
export async function consultarViabilidade(cep: string, numero: string): Promise<ResultadoViabilidade> {
    const resposta = await fetch("/api/viabilidade/consultar.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ cep, numero }),
    });
    return resposta.json();
}

// Quando atende, já manda pra página de planos/contratação com CEP e número
// resolvidos na URL — /contratar não precisa perguntar de novo.
export function irParaContratar(cep: string, numero: string): void {
    window.location.href = `/contratar?cep=${encodeURIComponent(cep)}&numero=${encodeURIComponent(numero)}`;
}

export const WHATSAPP_COMERCIAL = "https://wa.me/5527995057736";
