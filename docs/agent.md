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

**Um acontecimento, um episódio.** Se acabaste de criar um episódio e reparas que lhe
falta alguma coisa — uma ligação, o tipo, a data certa — **corrige-o com
`PUT /episodes/{id}`**. NUNCA crie um segundo episódio para completar o primeiro: ficam
dois registos do mesmo acontecimento, e daqui a um ano o `on-this-day` conta-o duas
vezes. O `PUT` aceita `links`, que substituem por completo os existentes, por isso
manda a lista toda — os que já lá estavam mais o novo.

O `title` descreve **o que aconteceu**, não o que tu fizeste. "Organizámos o estudo da
Sofia por matérias", nunca "Ligação à nota Sofia".

Repara no exemplo: a Sofia aparece em `links`, **não só no título**. Se o utilizador
nomeia alguém, o episódio tem de ficar ligado a essa pessoa — ver a secção seguinte.

**`type` é livre. Se nenhum dos que já existem servir, INVENTA UM** — não encaixes à
força no mais parecido. Um teste de matemática não é `saude`, é `escola`; um tipo errado
é pior do que um tipo novo, porque faz a memória aparecer na pergunta errada daqui a um
ano. Os que já existem: `compra`, `viagem`, `saude`, `escola`, `ideia`, `prenda`.

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
  `GET /api/v1/contacts?q=<nome>`, que devolve, por contacto:
  `{uid, name, email, org, relationship, groups, birthday, name_match, used_before}`.

  **Homónimos — nunca escolhas às cegas.** Uma pesquisa por um primeiro nome devolve
  facilmente dez resultados, e uma ligação à pessoa errada passa despercebida para
  sempre, porque o rótulo gravado diz o nome certo de qualquer maneira.

  Atenção a um erro que a lista convida a cometer: **a maioria dos resultados não são
  homónimos**, são contactos de *outras* pessoas que têm aquele nome escrito por
  referência — "Prof. Clara 1ºCiclo (Sofia)", "Ana Maia (Mãe Joana Colega Sofia)".
  Ninguém chama "Sofia" a essas pessoas. É para isso que serve o `name_match`:

  - `exact` — o contacto chama-se exatamente assim
  - `start` — o nome procurado abre o nome ("Sofia da Fonseca Dias")
  - `all_words` — disseste várias palavras e estão **todas** no nome, mas separadas
    ("Sofia Dias" → "Sofia da Fonseca Dias"); sinal forte
  - `word` — aparece como palavra inteira algures ("Avó Sofia")
  - `partial` — aparece só lá dentro; **quase nunca é a pessoa**

  **Procura pelo nome mais completo que o utilizador disser**, não só pelo primeiro. Se
  ele disse "Sofia Dias", procura `q=Sofia Dias`: a pesquisa cai sozinha para o primeiro
  nome se não encontrar nada, e o `all_words` isola a pessoa certa mesmo sem marcação de
  família. Encurtar o nome só deita fora informação que o utilizador te deu.

  Os sinais, do mais forte para o mais fraco:

  1. **`relationship`** (`CHILD`, `SPOUSE`, `PARENT`, …) — o utilizador declarou a
     relação nos Contactos. É o sinal mais forte que existe.
  2. **`groups`** contém "Família" — também posto à mão pelo utilizador.
  3. **`used_before > 0`** — já ligaste memórias a esta pessoa antes.
  4. **`name_match`** `exact`/`start`.

  Regra:

  1. **Um só candidato com sinal de família** (1 ou 2) → usa-o.
  2. **Nenhum com família, mas só um com `used_before > 0`** → usa esse e **diz na
     resposta quem escolheste**.
  3. **Vários empatados** → **pergunta**, listando-os com o `email`, a `org` ou o nome
     completo para serem distinguíveis. Não adivinhes pela ordem da lista.
  4. **Nenhum, ou só resultados `partial`** → cria o episódio sem a ligação e avisa que
     não encontraste o contacto. Nunca inventes um `ref`.

  Se o utilizador tiver de desempatar mais do que uma vez pela mesma pessoa, sugere-lhe
  que a marque nos Contactos (grupo "Família", ou o campo de relação) — configura-se uma
  vez e resolve o problema para sempre.
- `photo` / `file` / `folder` → file id do Nextcloud. Se tiveres um URL,
  passa-o por `GET /api/v1/files/resolve?link=<url>`, que devolve já o `kind` certo.

  **Notas da app Notes**: o `id` que a Notes devolve em `GET /apps/notes/api/v1/notes`
  **é** o file id do Nextcloud (confirmado). Para ligar uma nota, procura-a por título
  nessa lista e usa `{"kind": "file", "ref": "<id>", "label": "<título da nota>"}`.
  Usa o **título**, não o nome do ficheiro — o `resolve` devolveria `Sofia.md`, que é
  pior de ler daqui a um ano.

  E não confundas o pedido: ligar uma nota não é guardar a nota aqui. O texto continua
  a viver nas Notes e pode mudar mil vezes; o recall guarda o acontecimento com data e
  a referência para ela. Se não houver acontecimento nem data, não pertence ao recall.
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
