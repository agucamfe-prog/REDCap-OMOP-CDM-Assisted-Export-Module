# OMOP-CDM Export — módulo externo de REDCap

Mapea y exporta un proyecto de REDCap al **OMOP Common Data Model v5.4**.
Propone un destino para cada campo, deja revisarlo y editarlo, documenta el
mapeo y genera la exportación.

**Estado: versión 1.0.0.** Cura el mapeo, lo reutiliza entre proyectos,
comprueba los conceptos contra un vocabulario si lo hay, seudonimiza y exporta
un CDM que carga en PostgreSQL con el DDL oficial. Bilingüe (castellano /
inglés). Evaluado en 10 proyectos de equipos en castellano y en inglés.

## Cómo está hecho

El módulo se desarrolla con **especificaciones y pruebas primero**
(*Spec-Driven Development* + *Test-Driven Development*):

| Documento | Para qué |
|---|---|
| [`constitution.md`](constitution.md) | Los principios que no se negocian (solo se exporta lo aprobado, la ausencia por ausencia…) |
| [`AGENTS.md`](AGENTS.md) | Cómo contribuir: orden de lectura, comandos, reglas de código y de tests (personas y agentes de IA) |
| [`specs/`](specs/README.md) | 18 especificaciones en orden de desarrollo, cada una con `spec.md` (qué), `plan.md` (cómo) y `tasks.md` (10–15 tareas, test primero) |
| `tests/specs/` | Los tests de cada spec, que citan los requisitos `FR-NNN-XX` que comprueban |

`php tests/run.php --trace` ejecuta los 957 tests y comprueba que cada
requisito tiene al menos un test o un procedimiento de verificación manual.

---

## Instalar no descarga nada

Copia la carpeta `omop_cdm_export_v1.0.0` a `<redcap>/modules/`, habilítala en
*Control Center → External Modules*, y actívala en un proyecto.

El módulo **no crea ninguna tabla** y no necesita ficheros externos. Los
conceptos OMOP se buscan en athena.ohdsi.org desde el propio editor: cada fila
lleva un botón que abre la búsqueda con el dominio ya filtrado. El servidor no
consulta internet, salvo que el administrador configure la WebAPI de un ATLAS
(ver *Vocabulario*).

Requisitos: REDCap ≥ 12.0, PHP ≥ 7.4 con `mbstring`. Opcional: `pdo_sqlite`
para el índice local de vocabulario, `curl` para la WebAPI.

## Estructura

```
omop_cdm_export_v1.0.0/
├── config.json                  Metadatos, enlaces y ajustes de proyecto y de sistema
├── OmopCdmExportModule.php      Clase principal
├── classes/
│   ├── CdmModel.php             Tablas y columnas del CDM 5.4, operadores, UCUM
│   ├── DictionaryProfiler.php   Clasificador de campos
│   ├── CsvExporter.php          CSV, ZIP, informe y kit de carga
│   ├── IdRegistry.php           Identificadores estables entre exportaciones
│   ├── MappingAdvisor.php       Avisos sobre mapeos engañosos
│   ├── MappingAnnotation.php    El mapeo como etiquetas @OMOP del diccionario
│   ├── MappingDocument.php      Documento del mapeo (HTML, JSON, Usagi, diccionario)
│   ├── MappingStore.php         Estados, huellas, importación y persistencia
│   ├── Pseudonymizer.php        Desplazamiento de fechas y precisión del nacimiento
│   ├── TextNormalizer.php       Normalización de texto
│   ├── Transformer.php          Datos de REDCap → filas OMOP
│   └── Vocabulary.php           Índice SQLite de Athena o WebAPI de ATLAS
├── lang/
│   ├── Spanish.ini              Textos en castellano
│   └── English.ini              Mismas claves, en inglés
├── pages/
│   ├── dashboard.php            Panel de perfilado, solo lectura
│   ├── editor.php               Editor de mapeo
│   ├── export.php               Previsualización y descarga del CDM
│   └── vocabulary.php           Centro de Control: índice de vocabulario
├── resources/
│   ├── ddl/postgresql/          DDL oficial de OHDSI CDM v5.4 (Apache 2.0)
│   ├── OMOP_CDMv5.4_Field_Level.csv  Especificación oficial, para los tests
│   ├── build_vocab_index.php    Construir el índice desde la línea de comandos
│   └── NOTICE.txt               Procedencia y licencia de lo anterior
├── specs/                       Especificaciones (spec, plan, tareas) y plantillas
├── constitution.md              Principios del proyecto
├── AGENTS.md                    Guía de contribución
└── tests/
    ├── run.php                  php tests/run.php [NNN…] [--trace] — sin REDCap
    ├── bootstrap.php            Sustitutos del framework y de REDCap
    ├── lib/                     Marco de pruebas y proyecto de prueba compartido
    ├── specs/                   Un fichero de tests por spec
    ├── support/run_page.php     Ejecuta una página en un proceso aparte
    ├── fixtures/                Banco de pruebas, vocabulario y corpus de la evaluación (eval10/)
    └── eval/                    Scripts de la evaluación con 10 proyectos (solo línea de comandos)
```

## Alcance

El mapeo se cura en cada proyecto, pero **no se queda encerrado en él**: viaja
en el diccionario como etiquetas `@OMOP` y se puede importar de otro proyecto
(ver *El mapeo viaja con el instrumento*). Nada importado queda aprobado: cada
proyecto aprueba lo suyo.

El clasificador se mide con un banco de pruebas público incluido en
`tests/fixtures/benchmark.csv`: 120 campos reales en castellano e inglés con el
destino correcto (o los destinos aceptables, cuando hay más de uno defendible).
Resultado actual:

| Conjunto | Aciertos |
|---|---|
| Desarrollo (93 campos usados para ajustar las reglas) | 93/93 |
| Reservado (27 campos nunca usados para ajustar) | 26/27 (96 %) |

El reservado es la cifra honesta. El fallo que queda («Referring hospital»
propuesto como Observation) está a la vista en la salida de los tests y no se
ha corregido a propósito: corregirlo mirando el caso invalidaría el conjunto.

Además se evaluó sobre **10 proyectos simulados realistas** (5 de equipos en
castellano y 5 en inglés, 186 campos, 405 participantes): cardiología,
diabetes en Atención Primaria, COVID-19, cribado de cáncer de mama, ictus
multicéntrico en dos idiomas, asma pediátrica, sepsis en UCI, artritis
reumatoide y embarazo.

| Ronda | Aciertos | Castellano | Inglés |
|---|---|---|---|
| 1 — clasificador sin ajustar sobre estos proyectos | 154/186 (82,8 %) | 82/96 (85,4 %) | 72/90 (80,0 %) |
| 2 — tras corregir las causas de error encontradas | 184/186 (98,9 %) | 94/96 | 90/90 |

La ronda 1 mide la generalización; la 2, el ajuste. Las causas corregidas eran
generales (términos frecuentes que faltaban, frases de contexto como «at
discharge», escalas numéricas, fechas de diagnóstico, grafía británica), no
casos sueltos, y el conjunto reservado del banco de pruebas no empeoró.

Tres reglas explican la mayor parte del resultado: se clasifica por la **etiqueta** y solo se recurre al nombre
de la variable si aquella no da señal (un campo llamado `peso_visita` no es una
visita); gana la coincidencia **más específica** entre los dominios clínicos, no la del
primer dominio del orden ("pérdida de peso" es una condición, no una medida de
peso), salvo para los campos administrativos, que se reconocen aparte y con
prioridad absoluta: excluir un identificador no compite con clasificarlo; y las frases
que sitúan un dato en el tiempo se ignoran ("en el momento de la visita"
describe cuándo, no qué).

El idioma de las pantallas, del informe del ZIP y del documento de mapeo lo
decide el ajuste de idioma del propio módulo en cada proyecto
(*External Modules → Configure*, clave `reserved-language-project`), no el
idioma del proyecto de REDCap. Para añadir otro, copia un `.ini` con el nombre
del idioma y traduce los valores; no hay que tocar código. El clasificador está
calibrado en castellano e inglés y el banco de pruebas incluye los dos.

## Identificadores estables

`person_id`, `visit_occurrence_id`, `care_site_id` y `provider_id` salen de un
registro persistente del proyecto: cada persona o visita recibe un número la
primera vez y lo conserva en todas las exportaciones. Un número no se reutiliza
nunca, aunque su registro se borre. Si `person_id` fuera el orden del registro
en la exportación, borrar uno renumeraría a todos los siguientes.

Los identificadores de los hechos se derivan de lo que describen (registro,
evento, instancia, campo, opción) con un hash de 31 bits, que cabe en el
`integer` del DDL. Las colisiones, muy raras, se resuelven en orden de clave,
así que dos exportaciones de los mismos datos dan ficheros idénticos byte a
byte. La previsualización no consume números.

Con esto se pueden hacer cargas incrementales y enlazar el CDM con otras
fuentes por `person_id`.

## Seudonimización

Además del hash de `person_source_value`:

- **Desplazar fechas** hasta N días. Todas las fechas de una persona se
  mueven lo mismo —nacimiento, hechos, visitas, muerte—, distinto para cada
  persona y siempre distinto de cero. Edades e intervalos se conservan; las
  fechas reales no. El desplazamiento sale de una sal secreta del proyecto, así
  que es el mismo en todas las exportaciones. Si el proyecto solo recoge el
  año de nacimiento, ese año no se desplaza: mover días no tiene sentido sobre
  un año suelto.
- **Precisión del nacimiento**: fecha completa, año y mes, o solo año.
- **No exportar texto libre**: los campos de texto aprobados emiten su fila
  (el hecho ocurrió, y si hay un número se conserva) pero sin el texto; NOTE no
  se genera. Una medida que se queda sin ningún valor no se emite y se cuenta.
  Un campo de observaciones puede contener cualquier cosa.

El informe del ZIP dice qué se ha aplicado. Con el hash activo, el informe
tampoco lista los Record ID de las personas descartadas: se consultan en la
previsualización, dentro de REDCap.

## Vocabulario (opcional)

Sin vocabulario, el módulo solo puede mirar la *forma* de lo que se pega. Con
vocabulario comprueba cada `concept_id`:

| Comprobación | Severidad |
|---|---|
| El número no está en el vocabulario cargado | Avisa (puede ser un vocabulario no descargado) |
| Está retirado | Avisa |
| No es estándar | Avisa |
| Es de otro dominio (un concepto de Measurement en un diagnóstico) | Avisa |
| El nombre pegado no es el del número («Body height» con el id de «Body weight») | Avisa |

El último es la comprobación que más importa: una errata en el número produce
un CDM que carga sin errores y dice algo distinto de lo que mide el proyecto.

Además, el botón de la varita sugiere candidatos para cada campo y cada opción
(la misma búsqueda que el botón de Athena, resuelta en el servidor y con un clic
para elegir), al pegar solo el número se rellena el nombre, y `unit_concept_id`
y `SOURCE_TO_CONCEPT_MAP` se completan con el vocabulario.

Dos fuentes, configurables por el administrador:

- **Índice local**: descarga los vocabularios en athena.ohdsi.org con tu
  licencia, deja `CONCEPT.csv` en el servidor y constrúyelo en *Centro de
  Control → OMOP-CDM: vocabulario*, o con
  `php resources/build_vocab_index.php CONCEPT.csv índice.sqlite` si son
  millones de conceptos. SNOMED no sale del servidor.
- **WebAPI de ATLAS**: la URL de la WebAPI de tu institución y, si lo pide, un
  token.

Si el vocabulario no responde, el editor lo dice y no bloquea nada. Si la
búsqueda con todas las palabras no encuentra nada, se relaja a cualquiera de
ellas («fasting glucose» encuentra «Glucose»), y la grafía británica se
convierte a la americana de los vocabularios («haemoglobin» → «hemoglobin»).

## El mapeo viaja con el instrumento

El mapeo aprobado se puede escribir en el propio diccionario de datos, como
etiquetas de acción en la anotación de cada campo:

```
@OMOP-DOMAIN=Measurement
@OMOP-CONCEPT="3016102 | Mycobacterium sp identified in Sputum by Acid fast stain"
@OMOP-STRATEGY=value_concept
@OMOP-VALUES="1=9191 | Positive; 2=9189 | Negative; 3=-"
@OMOP-DATE=fecha_analitica
@OMOP-UNIT="mg/dL"
@OMOP-ENDDATE=fecha_fin
@OMOP-EXCLUDE
```

En `VALUES`, `-` significa «esta opción no genera fila». La pantalla de
exportación descarga el diccionario con esas etiquetas ya puestas («Diccionario
con @OMOP»); se sube en el Designer como cualquier diccionario, con su
revisión y su modo borrador. El módulo no escribe nunca en el proyecto. Las
demás anotaciones del campo (`@HIDDEN`, notas del equipo…) se conservan.

Desde ese momento el mapeo va en el XML del proyecto, en sus copias y en la
Shared Library de REDCap. Un proyecto que reutilice el instrumento lo verá en
el editor como propuesta (`@OMOP`), y «Usar las anotaciones @OMOP» lo adopta de
una vez. Sigue haciendo falta aprobarlo: que otro lo escribiera no aprueba nada
aquí. «Aprobar lo que está listo» aprueba en bloque lo que no tenga avisos.

La otra vía es **importar el `MAPEO.json`** de otro proyecto (va en su ZIP).
Solo se importan los campos con el mismo nombre, el mismo tipo y los mismos
**códigos** de opción. Las etiquetas pueden diferir —el mismo instrumento
traducido en un estudio multicéntrico—, pero entonces el campo llega con un
aviso que exige confirmar que cada código significa lo mismo: si «1» es
«Hombre» allí y «Mujer» aquí, copiar el concepto mentiría. Las exclusiones
también viajan; lo ya aprobado aquí no se toca.

Probado entre el centro de Londres (inglés) y el de Madrid (castellano) de un
estudio de ictus: 9 campos importados, 5 aprobados en bloque y 2 con el aviso
de etiquetas traducidas, confirmados uno a uno.

Probado con dos proyectos creados a partir del de tuberculosis: por las dos
vías llegan las 25 decisiones firmes y 17 se aprueban en bloque; las otras
tienen avisos y piden revisión.

## Cómo se cura

1. El clasificador propone un destino OMOP para cada campo, con un nivel de
   confianza y el motivo.
2. Acciones en bloque: excluir de golpe los campos sin destino y los de texto
   libre, que suelen ser un tercio del proyecto.
3. Campo a campo: se ajusta el dominio, se busca el concepto en Athena con el
   botón de la fila, y se pegan **el número y el nombre**.
4. Se aprueba. Todo se guarda solo, sin botón final.

Pegar el nombre además del número es lo que permite detectar más tarde una
errata en la cifra: la incoherencia queda escrita y es verificable en cualquier
sitio que tenga el vocabulario cargado.

## Estados del mapeo

| Estado | Significado |
|---|---|
| `proposed` | Lo que dice el clasificador. No es una decisión y no se exporta. |
| `approved` | Revisado y aceptado por una persona, con usuario y fecha. |
| `excluded` | Decisión firme de no mapear. |
| `review` | Estaba aprobado, pero el diccionario de REDCap cambió después. |
| `config` | Lo consume la configuración (Record ID, nacimiento, fecha de muerte, centro, profesional): no se mapea ni cuenta como pendiente. Estado derivado, nunca guardado. |

Cuatro reglas que el resto del módulo da por hechas:

- **Solo se exporta lo que está en `approved`.** Falla en cerrado.
- **Escribir no aprueba.** Rellenar el concepto deja el campo en `proposed`.
- **Modificar retira la aprobación.** Cambiar el contenido de un campo aprobado
  lo devuelve a `proposed`: aprobar es una decisión sobre un contenido concreto.
  Solo lo que *cambia*: entrar y salir de una casilla sin tocarla no retira nada.
- **Lo guardado manda.** La propuesta del clasificador (estrategia, fecha,
  dominio) se aplica solo la primera vez que se guarda un campo. Después, nada
  la vuelve a imponer; elegir «fecha por defecto» a propósito también se respeta.
- **La deriva del diccionario se detecta.** Al aprobar se guarda una huella de
  los metadatos del campo. Si alguien lo edita después —añade una opción, cambia
  el tipo—, la huella deja de cuadrar y el campo pasa solo a `review`.

Si el campo de fecha que fechaba un hecho aprobado desaparece del diccionario,
el campo también pasa a `review`: sin esto se exportaba con la fecha por
defecto sin que nadie lo hubiera decidido.

Editar el mapeo exige derechos de diseño de proyecto. Cada cambio de estado
queda en el registro del módulo con usuario, campo y `concept_id`.

## Derechos y grupos de acceso

| Quién | Puede |
|---|---|
| Cualquier miembro del proyecto | Ver el panel, el editor en solo lectura y el documento del mapeo |
| Con derechos de diseño | Además, editar y aprobar el mapeo |
| Con diseño y exportación completa en los instrumentos de los que sale algún dato | Además, previsualizar y descargar el CDM |

Por defecto el framework de módulos solo deja entrar a quien tiene diseño, lo
que dejaba fuera justo a quien necesita revisar el mapeo sin tocarlo: el
investigador principal, el monitor o el estadístico. El módulo lo abre a todo
el equipo para leer, porque esas páginas solo contienen metadatos.

Generar el CDM es una exportación de datos de participantes, así que no basta
con poder editar el mapeo. Hace falta además exportación **completa**
(«Full data set») en todos los instrumentos de los que sale algún dato: los de
los campos aprobados, los de sus fechas de referencia y de fin, y los de los
campos que consume la configuración (nacimiento, sexo, raza, etnia, fecha de
fallecimiento, centro, profesional). Si falta en uno, la pantalla de
exportación dice cuál y no deja descargar.

Un usuario dentro de un DAG exporta solo los registros de su grupo, y el
informe del ZIP lo indica. `REDCap::getData` devuelve todos los grupos si no se
le pide otra cosa, y sin este filtro un centro se llevaba los participantes de
los demás.

## Proyectos longitudinales

El motor procesa eventos e instrumentos repetibles. Cada combinación de evento
e instancia es una **ocasión de recogida**, y esa es la granularidad de un
hecho: un mismo campo medido en tres visitas genera tres filas, cada una con la
fecha de su evento.

Si se activa la generación de visitas, cada ocasión produce una fila en
`VISIT_OCCURRENCE`. Una ocasión es un evento, o cada instancia cuando se repite
el evento entero; un instrumento repetible *dentro* de un evento (la medicación
de la visita basal) pertenece a la visita de ese evento. Requiere elegir un
`concept_id` de tipo de visita: sin él no se generan, porque una visita con
concepto 0 no aporta nada.

La visita empieza en la fecha por defecto de la ocasión y se alarga con los
hechos posteriores de esa misma ocasión (la analítica del día siguiente). Un
hecho fechado *antes* de la visita —una fecha de diagnóstico de hace meses
recogida en la basal— no la estira hacia atrás y queda sin
`visit_occurrence_id`, porque no ocurrió en esa visita; el informe los cuenta.
Ningún hecho queda enlazado a una visita fuera de cuyas fechas cae, y DEATH
nunca crea visitas.

Una instancia de un instrumento repetible solo trae sus propios campos: si su
fecha de referencia es la de la visita, se toma de la fila principal del
**mismo** evento, nunca de otro.

Los registros se procesan en lotes de 200, así que la memoria no crece con el
tamaño del proyecto.

## Proyectos grandes

El editor cachea por petición el perfilado y las filas del mapeo, y el módulo
cachea sus ajustes: el framework no lo hace y cada `getProjectSetting()` es una
consulta. Sin esa caché, exportar 50 registros con 298 campos aprobados
(15.000 filas) lanzaba unas 60.000 consultas y tardaba 144 s; con ella son 62
consultas y unos 7 s en el mismo entorno de desarrollo, con Xdebug activo.

Los controles que se repiten en cada fila del editor (dominios, fechas, ayuda)
se rellenan en el navegador a partir de una sola lista: un proyecto de 500
campos pasó de 6,1 a 4,0 MB de HTML.

## Personas descartadas

`year_of_birth` es NOT NULL en el CDM y no se puede inventar: sin él no se
calcula la edad, que es la variable de estratificación universal en OHDSI. Una
persona sin año de nacimiento se descarta **junto con todos sus hechos**, para
no dejar filas huérfanas, y se cuenta en el informe.

Si el proyecto no declara campo de fecha de nacimiento, la pantalla de
exportación avisa antes: sin él la exportación saldría vacía.

## PERSON y DEATH: lo que viene de la configuración

`PERSON` y `DEATH` son tablas de una fila por persona y se construyen desde
campos declarados en la configuración, no desde hechos sueltos:

- **Sexo, raza y etnia.** La configuración dice *dónde* está el dato; *qué*
  concepto corresponde a cada opción se decide en el editor y, como todo lo
  demás, **solo cuenta si el campo está aprobado**. Mientras no lo esté, el
  concepto sale a 0, la etiqueta original queda en `*_source_value` y el editor,
  la exportación y el informe lo avisan.
- **Fecha de muerte.** Si el campo declarado tiene valor, la persona murió ese
  día. Un «Exitus sí/no» mapeado a Death se fecha con esa misma fecha, nunca con
  la primera fecha de su formulario (que solía ser la de cierre del caso).
  En DEATH el concepto es la **causa** (`cause_concept_id`): para decir solo que
  alguien murió no hace falta concepto, y un campo de causa en texto libre
  aprobado como Death va a `cause_source_value`.
- **Centro y profesional.** Un campo de opciones con el centro genera
  `CARE_SITE` (nombre = la etiqueta de la opción) y se enlaza desde `PERSON` y
  `VISIT_OCCURRENCE`; uno con el profesional genera `PROVIDER` y se enlaza
  desde las visitas y los hechos de cada ocasión. Solo campos de opciones: un
  texto libre daría un centro por cada forma de escribirlo. De los
  profesionales sale únicamente el código de la opción, nunca el nombre.

## Casillas de verificación

REDCap devuelve las casillas de dos formas distintas. Con `return_format=array`,
que es el que usa este módulo, llegan como un array anidado bajo el nombre del
campo: `$row['comorbilidades']['3']`. El formato con guiones bajos
—`comorbilidades___3`— es el de la exportación a CSV.

El transformador acepta los dos, porque la estructura ha variado entre
versiones de REDCap y asumir solo una hizo que las casillas se perdieran
enteras y sin ningún aviso.

Una fila donde no hay ninguna casilla marcada no cuenta como descarte: no es un
dato que se pierda, es un dato que no existe.

## Transformación y exportación

Reglas del motor, todas verificadas con pruebas:

- **Solo entra lo aprobado.** Ningún otro estado produce filas.
- **La ausencia se representa por ausencia.** Un «No» en un diagnóstico no
  genera fila en `CONDITION_OCCURRENCE`. Emitirlo inflaría cualquier cohorte
  construida después. Cada opción tiene su marca de *genera fila*.
- **Ninguna fila sin fecha**, en ninguna tabla, OBSERVATION incluida. Una
  fila sin ella se descarta y se cuenta.
- **Un código de REDCap no es un valor.** El «2» de un desplegable no llega
  nunca a `value_as_number`; la respuesta va como concepto o como texto, salvo
  en las escalas numéricas («0 - Sin dolor» … «10»).
- **Un campo de fecha puede ser el hecho.** «Fecha de diagnóstico de TB»
  mapeada a Condition se fecha con su propio valor.
- **Todo descarte se cuenta y se explica** en el informe que viaja dentro del
  ZIP, en el idioma del proyecto.

### Estrategias de valor de un categórico

| Estrategia | Qué genera | Para qué |
|---|---|---|
| Un concepto por opción | Cada opción es un hecho con su concepto | Comorbilidades, fármacos de un desplegable, sí/no |
| Concepto de la pregunta + valor por opción | Una medida u observación con la respuesta en `value_as_concept_id` | Resultados: «Baciloscopia» = «Positiva» |
| Un concepto para todo el campo | Una fila con la etiqueta como texto o, en una escala, su número | Escalas de valoración, categóricos descriptivos |

La segunda es la propuesta por defecto para los categóricos de Measurement y
Observation que no son un sí/no. Exportar un resultado categórico opción por
opción daría un `measurement_concept_id` = «Positive» con el código de REDCap
como valor numérico, que no es lo que se midió.

Otras reglas del motor:

- **Unidades**: `unit_source_value` se traduce a su código UCUM («mmHg» →
  `mm[Hg]`) y de ahí a `unit_concept_id`, con el vocabulario o, sin él, con una
  tabla corta de unidades seguras (kg, cm, mm[Hg], %, g/dL, mg/dL, mmol/L,
  µmol/L). Una unidad que no se reconoce se deja sin concepto: un
  `unit_concept_id` equivocado es peor que uno vacío.
- **Operadores**: en MEASUREMENT, «< 50» se guarda como `operator_concept_id`
  «<» y `value_as_number` 50. No es interpretar: es exactamente lo que dice el
  original, en la columna que el CDM tiene para eso.
- **Fechas de fin**: los campos de Condition, Drug, Procedure, Device y Visit
  pueden declarar una fecha de fin. Una fecha de fin anterior al inicio se
  descarta y se cuenta.
- **NOTE**: un campo de texto libre aprobado en el dominio Note genera una
  nota con el texto completo; su concepto es la clase de nota.

Tablas generadas: `PERSON`, `OBSERVATION_PERIOD`, `VISIT_OCCURRENCE`,
`CONDITION_OCCURRENCE`, `DRUG_EXPOSURE`, `PROCEDURE_OCCURRENCE`, `MEASUREMENT`,
`OBSERVATION`, `DEVICE_EXPOSURE`, `SPECIMEN`, `NOTE`, `DEATH`, `CARE_SITE`,
`PROVIDER`, `SOURCE_TO_CONCEPT_MAP` (si hay vocabulario: `target_vocabulary_id`
es obligatorio y solo él lo sabe) y `CDM_SOURCE`, con las columnas en el orden
oficial del DDL. Los tests comparan las 17 definiciones con
`OMOP_CDMv5.4_Field_Level.csv` (columnas, orden y NOT NULL) en cada ejecución.

## Qué hay en el ZIP

| Fichero | Para qué |
|---|---|
| `*.csv` | Las tablas del CDM |
| `INFORME.txt` | Qué se exportó, qué se descartó y por qué, qué seudonimización se aplicó |
| `MAPEO.html` / `MAPEO.json` | El documento del mapeo; el JSON se importa en otro proyecto |
| `MAPEO_usagi.csv` | El mapeo en el formato de Usagi, para revisarlo con vocabulario |
| `DICCIONARIO_anotado.csv` | El diccionario con las etiquetas `@OMOP` |
| `carga_cdm/` | DDL oficial de OHDSI, carga con `\copy`, claves, índices, `cargar.sh` y `dqd.R` |

`sh carga_cdm/cargar.sh` crea el esquema (por defecto `cdm`, configurable),
carga los CSV y añade claves e índices; `dqd.R` pasa el Data Quality Dashboard
cuando se han cargado los vocabularios de Athena. Probado en PostgreSQL 17: la
exportación del proyecto de prueba carga entera, sin ninguna FK rota, sin
hechos fuera de su visita ni de su periodo de observación.

`OBSERVATION_PERIOD` se deriva de la primera y la última fecha vistas por
sujeto, incluidas las fechas de fin de los hechos. Una persona sin ninguna fecha no obtiene periodo, y eso se avisa: las
herramientas de OHDSI ignoran a esas personas.

## Datos que no se pueden mapear

Cuando un campo de texto libre contiene lo que debería ser un número, el
módulo **no lo interpreta**: la fila se exporta con el texto original en
`value_source_value` y `value_as_number` vacío, y el informe lo cuenta. `1.234`
es mil doscientos treinta y cuatro o uno coma dos según el idioma, y «95,5»
tampoco se convierte. Solo se convierte lo que no admite dos lecturas (`95`,
`95.5`, y `< 50` como operador más número); con validación numérica de REDCap
el formato está garantizado y se convierte siempre. Un valor perdido se cuenta
en el informe; uno inventado no se detecta nunca.

El arreglo correcto está en el Designer de REDCap, poniendo la validación que
falta. Cuando el proyecto ya no se puede tocar, la decisión honesta es excluir
el campo y dejar constancia en el documento de mapeo.

## Búsqueda en Athena

Cada fila del editor lleva dos botones que abren athena.ohdsi.org con la
búsqueda ya escrita:

- **Athena**, el principal, filtra por dominio, conceptos estándar
  (`standardConcept=Standard`) y válidos (`invalidReason=Valid`), **sin filtro
  de vocabulario**: cuando el concepto vive en otro vocabulario (dolor,
  síntomas…), un filtro que no acierta deja cero resultados, y quedarse sin
  nada es peor que ver algo de ruido.
- Un segundo botón con el nombre del **vocabulario según el dominio** (LOINC
  para Measurement, RxNorm para Drug, Gender para Person, SNOMED para
  Condition, Procedure, Observation, Device, Specimen, Death y Visit) añade ese
  filtro. Sin él, buscar un analito devuelve sobre todo conceptos SNOMED de
  clase *Procedure*, que representan el acto de medir y no el analito medido.
  En Note y en los dominios propios del módulo no hay segundo botón.

Cada opción de un categórico lleva su propio botón principal.

La búsqueda se construye traduciendo la etiqueta al inglés y quitando todo lo
que Athena no entiende:

- **Las unidades**, que se sustituyen por la **propiedad** que describen. En
  LOINC el nombre del concepto lleva la propiedad, no la unidad: `g/dL` es
  *Mass/volume*, `U/L` es *Enzymatic activity/volume*, `%` es una fracción. Así
  «Hemoglobina (g/dL)» se busca como `hemoglobin mass volume`, que descarta la
  glicosilada y la fetal, en vez de `hemoglobin kg dl`, que no descarta nada.
  El peso y la talla son la excepción: sus conceptos LOINC no llevan la
  propiedad en el nombre, así que se buscan como `body weight` a secas.
- **Los calificadores del protocolo.** `basal`, `de control`, `actual`,
  `realizada`. «Peso basal» y «Peso en esta visita» son el mismo concepto OMOP;
  lo que los distingue es la fecha del hecho.
- **La redacción de la pregunta.** «¿Presentó…?», «Por favor, indique…».

El glosario traduce los términos clínicos frecuentes, con las frases largas
antes que las cortas para que «sudoración nocturna» dé `night sweats` y no
`sweating`, y también las **etiquetas de opción**: «Hombre» busca `male`,
«No realizada» busca `not performed`.

El dominio que se envía es el del CDM, no el nombre de la tabla: los campos que
alimentan `PERSON` se buscan en el dominio **Gender**, porque `Person` no es un
dominio de Athena y filtrar por él no devuelve nada. Los de `DEATH` se buscan
en **Condition**, que es donde viven las causas de muerte, y las respuestas de
la estrategia «valor por opción» en **Meas Value** (Positive, Detected…).

En Athena hay dos columnas fáciles de confundir: **ID** es el `concept_id` (un
entero) y **CODE** es el código del vocabulario original (`2089-1`). El editor
detecta cuando se pega el segundo y lo explica en vez de fallar.

## Documento del mapeo

El mapeo curado es el artefacto de valor: es lo que se revisa, se discute con el
investigador principal y sobrevive a quien lo hizo. Desde la pantalla de
exportación se genera en dos formatos, y ambos viajan además **dentro del ZIP**,
porque un CDM sin su mapeo es un dato sin procedencia.

- **HTML** autocontenido, sin CSS externo ni JavaScript: se archiva, se envía o
  se imprime a PDF y se seguirá leyendo dentro de años. Incluye la
  configuración, el resumen por dominio, cada campo con su concepto, unidad y
  fecha de referencia, quién lo aprobó y cuándo, los avisos pendientes, y las
  opciones que **no** generan fila.
- **JSON** para versionar y comparar entre exportaciones.

Los campos no exportados se listan también: decidir no exportar algo es una
decisión del mapeo y debe quedar constancia.

El documento es además la única forma de detectar el error que ninguna
comprobación automática puede ver: un `concept_id` válido pero equivocado.
Athena ordena por relevancia y las variantes de un mismo analito salen juntas,
así que es fácil elegir *Cholesterol in LDL **acylated*** en lugar del LDL
básico, o la glucosa **media** en lugar de una medición puntual. Los dos cargan
sin error y los dos producen un CDM que dice algo distinto de lo que mide el
proyecto. Leyendo el nombre del concepto en el documento se ven.

Por eso el editor avisa cuando el nombre pegado no parece venir de Athena. Sin
vocabulario no se puede verificar nada: lo único observable es la forma del
texto. Los vocabularios de OHDSI están en inglés y sus nombres son sintagmas
nominales cortos, así que una pregunta, una instrucción, una frase larga o un
texto en castellano no puede ser el nombre de un concepto.

La coincidencia con la etiqueta del proyecto **no** se usa como señal: un
cuestionario en inglés con un campo `Body weight` y el concepto LOINC
`Body weight` coincide siendo correcto. La regla es conservadora a propósito;
ante la duda no avisa, porque un aviso falso repetido enseña a ignorarlos todos.

## Volver a la propuesta

El mapeo guardado gana siempre a la propuesta del clasificador: si el
clasificador pisara lo curado, mejorar sus reglas borraría trabajo sin avisar.

Por eso hay una acción explícita para pedir que lo vuelva a mirar. El botón
**Propuesta** aparece en las filas cuyo dominio guardado difiere del que
propone el clasificador hoy, y hay una acción en bloque para revisarlas todas.
Se conservan conceptos, unidades, fechas y notas; solo cambia el dominio, y el
campo vuelve a `proposed` porque el destino ha cambiado. Lo aprobado nunca se
toca en la acción de bloque, y cada cambio queda en el registro.

## Lo que no es un hecho

Además de los identificadores, el clasificador reconoce las **entidades del
sistema sanitario** y no las propone como hechos: centros, hospitales,
servicios, plantas, profesionales y vínculos entre personas.

En el CDM un centro es una `CARE_SITE`, un profesional un `PROVIDER` y una
relación entre personas una `FACT_RELATIONSHIP`: se referencian desde los
hechos, no son hechos. Y sus valores son entidades locales sin concepto
estándar — no existe un `concept_id` para «Centro Norte».

Sin esto caían en el cajón de sastre (*categórico sin señal → Observation*) y
el editor invitaba a buscar cuatro conceptos que no existen. Proponer trabajo
inútil es peor que no proponer nada.

La distinción es la forma de la etiqueta: una entidad se nombra y la etiqueta
*empieza* por ella («Centro de Salud», «Médico responsable», «Clinic»). Si la
entidad aparece en medio, la etiqueta habla de algo que le pasó al paciente
—«Ingreso en hospital», «Admitted to hospital», «Number of hospital visits»,
«¿Derivado a centro de salud?»— y sí se exporta. Excluir cualquier etiqueta
que contuviera «hospital» escondería esos hechos.

Por la misma razón, «nombre» no es por sí solo una palabra clave de identificación:
«Nombre» a secas sí lo es, pero «Nombre del medicamento» es el dato más
importante de un fármaco.

## Lo que el editor no pregunta

Un campo que no llega al CDM —un identificador, una entidad estructural, un
campo calculado, una fecha de referencia— no muestra controles de concepto, ni
estrategia de valor, ni unidad, ni fecha. Solo el motivo por el que no se
exporta.

Pedir un concepto para «Centro de Salud» sería invitar a un trabajo que no
sirve para nada, y encima a buscar algo que no existe.

## Valores por defecto

El editor llega con lo previsible ya puesto, para que curar sea confirmar y no
rellenar:

- **La fecha de referencia** del propio instrumento del campo.
- **La estrategia de valor**: los categóricos empiezan en *un concepto por
  opción*, que es lo que necesitan un sexo, un sí/no o un desplegable de
  comorbilidades. La excepción son las escalas de valoración —un dolor de 1 a
  10—, que llevan un único concepto de severidad con el número como valor.
- **La unidad**, cuando la etiqueta la lleva entre paréntesis.

Una escala se reconoce por sus **etiquetas**, no por sus valores brutos: en
REDCap todo categórico usa 1, 2, 3 por convención, así que mirar el valor
convertía cualquier desplegable de cinco opciones en una escala.

## Acciones en bloque

Todas preguntan primero a quién afectarían y enseñan la lista. **Ninguna toca
lo aprobado ni lo marcado para revisar**: actúan solo sobre lo pendiente.

- **Aprobar lo que está listo**: aprueba los campos que una persona ya ha
  preparado (tienen concepto o decisiones guardadas) y no tienen ningún aviso.
  Lo que tenga avisos sigue necesitando aprobación campo a campo.
- **Excluir sin destino**, **excluir texto libre**, **excluir todo lo pendiente**.

## Reiniciar el mapeo

El botón **Reiniciar todo el mapeo** borra todas las decisiones del proyecto y
lo devuelve a la propuesta inicial del módulo: sin aprobaciones, sin
exclusiones, sin conceptos.

Pide confirmación dos veces: primero enseña con cifras qué se va a perder, y
después exige escribir `BORRAR` (`DELETE` en inglés), que el servidor vuelve a
comprobar. Antes de borrar se guarda una copia completa y el editor ofrece
**Deshacer el reinicio**; las aprobaciones vuelven con su firma original. Todo
queda registrado en el log de REDCap.

## Avisos de diseño

El clasificador dice a qué se parece un campo. El asesor dice otra cosa: si el
mapeo que estás a punto de aprobar va a producir un dato **engañoso**. Un mapeo
puede ser formalmente correcto y aun así generar filas que mienten.

Tres severidades:

- **Bloquea** — impide aprobar. Campos de estado de REDCap (`*_complete`),
  `concept_id` negativo, campo de fecha inexistente, y hechos sin ninguna fecha
  posible.
- **Avisa** — deja aprobar, pero exige confirmación explícita y queda registrado
  en el log. El caso central es un sí/no que no dice *qué* se administró
  mapeado a `Drug`: genera exposiciones a un concepto genérico mientras el
  fármaco real se queda en un texto libre. También: identificadores mapeados
  como hechos, medidas sin unidad, escalas de valoración mapeadas opción por
  opción, fechas de otro instrumento, y sí/no clínicos que emiten también el
  «No».
- **Informa** — se muestra y ya.

Entre los avisos hay tres que no son sobre el mapeo sino sobre el **diseño del
instrumento**, porque tienen arreglo barato en el Designer y caro después: una
etiqueta que dice «Fecha de inicio» sobre un campo de texto sin validación de
fecha, un «Recuento CD4» sobre texto libre, o una unidad entre paréntesis en un
campo que no admite números. En los tres casos REDCap no garantiza el formato y
el dato deja de ser transformable.

La marca de identificador de REDCap se respeta: un campo marcado se propone
como no exportable, porque acabaría escrito tal cual en un `*_source_value` del
CDM. Se exceptúan la fecha de nacimiento y el sexo, que el CDM necesita para
`PERSON` y suelen estar marcados con razón.

Dos de los avisos cubren fallos que no se ven en ninguna pantalla porque el
mapeo parece correcto: un campo aprobado en dominio **Person** no genera
ninguna fila —la tabla `PERSON` se construye desde los ajustes, no desde los
campos aprobados—, y un valor **numérico** aprobado en `Drug`, `Condition`,
`Procedure`, `Device` o `Specimen` pierde el número, porque esas tablas no
tienen columna de valor: queda constancia de que el hecho ocurrió y se tira el
dato que importaba.

El listón para bloquear es alto a propósito: el módulo asiste, no manda. Quien
sabe lo que hace debe poder seguir adelante, dejando constancia.

## El contrato de mapeo

Cada campo se describe con: `state`, `domain`, `concept_id`, `concept_name`,
`value_strategy` (`per_choice`, `value_concept` o `single_concept`),
`date_field`, `date_default` (se eligió la fecha por defecto a propósito),
`end_date_field`, `unit_source_value`, `notes`, `edited`, `source`
(`annotation` o `import` si llegó así), más la huella y la firma de quien
aprobó. Cada opción de un categórico lleva `emit`, `concept_id` y
`concept_name`. Se guarda como JSON en los ajustes de proyecto del módulo
(con `meta.schema_version`, para poder evolucionar el formato).

## Hoja de ruta

Las 18 especificaciones de [`specs/`](specs/README.md) están implementadas, en
cinco fases: fundamentos (001–004), curación (005–008), transformación y
exportación (009–012), reutilización y garantías (013–017) y validación con
proyectos realistas (018). Pendiente: ejecutar el Data Quality Dashboard con
los vocabularios completos de Athena y validar con proyectos y usuarios reales.

## Tests

```
php tests/run.php                 # todas las specs (957 tests)
php tests/run.php 004 009         # solo esas specs
php tests/run.php --trace         # además, trazabilidad requisito ↔ test
php tests/run.php --quiet         # solo fallos y resumen
```

No necesita REDCap: `tests/bootstrap.php` sustituye la clase base del framework,
`REDCap::getData` y `Project` por versiones en memoria, así que se prueba el
código real del módulo. Las páginas se ejecutan en un proceso PHP aparte
(`tests/support/run_page.php`) que devuelve su salida, cabeceras y código HTTP.

Hay un fichero por spec en `tests/specs/`, y cada test cita los requisitos que
comprueba. Entre otras cosas cubren: las 17 tablas frente a la especificación
oficial, el clasificador frente al banco de pruebas y al corpus de la
evaluación (`tests/fixtures/eval10/`), cada aviso del asesor, la
transformación de proyectos clásicos y longitudinales, los identificadores
estables, la seudonimización, el vocabulario, los derechos y el contenido del
ZIP. Con `--trace`, el ejecutor falla si un requisito no tiene test (ni
verificación manual declarada) o si un test cita un requisito inexistente.

El código de salida es 1 si algo falla, para integración continua.

## Decisiones tomadas

- **CDM v5.4.** Es la versión vigente. La v6.0 no está soportada por ATLAS,
  Achilles ni el Data Quality Dashboard.
- **Vocabulario opcional, nunca incluido.** Empaquetar SNOMED CT no es posible:
  la licencia que se acepta en Athena prohíbe distribuirlo. El módulo funciona
  sin vocabulario y lo usa si el administrador lo configura con su propia
  descarga o con la WebAPI de su ATLAS.
- **El mapeo vive en los ajustes de proyecto**, no en tablas propias. Instalar el
  módulo no toca el esquema de la base de datos. Para que viaje, se escribe en el
  diccionario como etiquetas `@OMOP`, que sube una persona en el Designer.
- **Solo lectura sobre los datos clínicos.** El módulo nunca escribe en el
  proyecto REDCap: ni en los datos ni en el diccionario.

## Aviso sobre los conceptos de los ejemplos

Los `concept_id` del vocabulario de prueba (`tests/fixtures/vocab`) y de los
ejemplos de este documento son reales en su mayoría, pero el fichero de prueba
no es una referencia: compruébalos siempre en Athena. Lo mismo para los dos
conceptos de idioma que NOTE usa por defecto (4180186 English language, 4182511
Spanish language): están en la configuración para que se puedan cambiar.

## Licencia

Apache License 2.0: ver [`LICENSE`](LICENSE) y [`NOTICE`](NOTICE). Es la misma
licencia que el DDL oficial de OHDSI incluido en `resources/ddl/`, e incluye una
concesión explícita de patentes. Copyright 2026 Fernando Agustín Camacho.
