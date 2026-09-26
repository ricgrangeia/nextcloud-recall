# Recall — guia para o agente

Memória episódica: **o que aconteceu, quando aconteceu, e a quem ou ao que está ligado.**
Não é um bloco de notas. Serve para responder mais tarde a perguntas com eixo temporal
("o que aconteceu nesta altura em anos anteriores?", "o que comprei no ano passado?",
"o que me lembro sobre esta pessoa?").

App id: `recall`. Todas as operações abaixo são rotas **OCS**, chamáveis com `app_api_call`.

---

## A regra que se erra primeiro

**`occurred_at` é a data em que a coisa ACONTECEU, nunca a data de hoje.**

Se o utilizador disser "no verão passado fomos ao Gerês", o `occurred_at` é do verão
passado. A data de registo é guardada à parte, automaticamente. Se não souberes a data
exata, usa `occurred_precision`:

- `day` — sabes o dia (por omissão)
- `month` — só sabes o mês ("em março")
- `year` — só sabes o ano ("em 2019")

Nunca inventes um dia para preencher o campo. Se o utilizador não disse quando, **pergunta**.

---

## Que operação usar

| A pergunta é… | Usa |
|---|---|
| "o que aconteceu nesta altura em anos anteriores?" | `GET /episodes/on-this-day` |
| "o que aconteceu em <período>?" | `GET /episodes?from=&to=` |
| "o que me lembro sobre <pessoa>?" | `GET /episodes?link_kind=contact&link_ref=<uid>` |
| procurar por palavras | `GET /episodes?q=` |
| guardar algo que aconteceu | `POST /episodes` |

O `on-this-day` devolve **agrupado por ano**, que é como a pergunta é feita
("no ano passado…", "há três anos…"). Não o reproduzas como lista corrida.

---

## Operações

```
GET    /ocs/v2.php/apps/recall/api/v1/episodes
         ?from=YYYY-MM-DD &to=YYYY-MM-DD &type= &q= &limit= &offset=
         ?link_kind=contact|photo|file|folder|event|task &link_ref=<id>

GET    /ocs/v2.php/apps/recall/api/v1/episodes/on-this-day
         ?date=YYYY-MM-DD   (por omissão: hoje)
         &window=<dias à volta da data, por omissão 0>
         &years_back=<quantos anos para trás, por omissão 5>

POST   /ocs/v2.php/apps/recall/api/v1/episodes
GET    /ocs/v2.php/apps/recall/api/v1/episodes/{id}
PUT    /ocs/v2.php/apps/recall/api/v1/episodes/{id}
DELETE /ocs/v2.php/apps/recall/api/v1/episodes/{id}

GET    /ocs/v2.php/apps/recall/api/v1/contacts?q=<nome>
GET    /ocs/v2.php/apps/recall/api/v1/files/resolve?link=<url ou file id>
```

### Criar um episódio

```json
{
  "title": "Prenda de anos da Sofia",
  "occurred_at": "2024-09-26",
  "occurred_precision": "day",
  "type": "compra",
  "body": "Livro de aguarelas",
  "source": "agent",
  "meta": { "valor": 24.90 },
  "links": [ { "kind": "contact", "ref": "<uid>", "label": "Sofia" } ]
}
```

Obrigatórios: `title` e `occurred_at`. Põe sempre `"source": "agent"` no que criares —
é o que distingue o que deduziste do que o utilizador escreveu à mão.

Repara no exemplo: a Sofia aparece em `links`, **não só no título**. Se o utilizador
nomeia alguém, o episódio tem de ficar ligado a essa pessoa — ver a secção seguinte.

`type` é livre; usa os que já existem quando servirem: `compra`, `viagem`, `saude`,
`ideia`, `prenda`.

---

## Ligações

**Se a memória menciona uma pessoa, LIGA-A. Não basta escrever o nome no título.**

"dei um livro à Sofia" não é um episódio com a palavra "Sofia" lá dentro — é um episódio
**ligado à Sofia**. Sem a ligação, daqui a um ano ninguém consegue cruzar o aniversário
dela com o que lhe deste, que é metade da razão de esta app existir. O mesmo vale para
uma foto, um evento ou uma tarefa que o utilizador refira.

Antes de criar o episódio: vê que pessoas, ficheiros ou eventos são mencionados, resolve
cada um no identificador estável (ver abaixo) e inclui-os no array `links`. Se a pesquisa
não devolver ninguém com esse nome, cria o episódio na mesma e **diz ao utilizador que não
encontraste o contacto** — não inventes um `ref`.

`kind` tem de ser um de: `contact`, `photo`, `file`, `folder`, `event`, `task`.

O `ref` é **sempre um identificador estável**, nunca um nome nem um caminho:

- `contact` → UID do CardDAV. Não o sabes de cor: descobre-o com
  `GET /api/v1/contacts?q=<nome>`, que devolve `{uid, name, email, org, used_before}`.

  **Homónimos — nunca escolhas às cegas.** É comum haver duas pessoas com o mesmo nome,
  e uma ligação à pessoa errada passa despercebida para sempre, porque o rótulo gravado
  diz o nome certo de qualquer maneira. Regra:

  1. **Um só resultado** → usa-o.
  2. **Vários, mas só um com `used_before > 0`** → usa esse (é a pessoa a quem o
     utilizador já ligou memórias antes) e **diz na resposta qual escolheste**.
  3. **Vários empatados** → **pergunta**, listando-os com o `email` ou a `org` para
     serem distinguíveis. Não adivinhes pela ordem da lista.
  4. **Nenhum** → cria o episódio sem a ligação e avisa que não encontraste o contacto.
- `photo` / `file` / `folder` → file id do Nextcloud. Se tiveres um URL,
  passa-o por `GET /api/v1/files/resolve?link=<url>`, que devolve já o `kind` certo.
- `event` / `task` → UID do CalDAV do evento ou da tarefa.

O `label` é o nome legível **no momento em que ligas**. Grava-o sempre: a foto pode ser
apagada e o contacto pode desaparecer, e a memória tem de continuar a ler-se sem eles.

---

## Quando NÃO guardar

O valor do `on-this-day` vem de as memórias serem poucas e escolhidas. Uma memória por
dia enche isto de trivialidades e destrói a utilidade da consulta — e ninguém volta atrás
para limpar.

- Não guardes por tua iniciativa o que é rotina ou ruído de sistema.
- Se achares que algo merece ser recordado, **pergunta ao utilizador** antes de gravar.
- Não gravar é sempre recuperável; gravar lixo, na prática, não é.
