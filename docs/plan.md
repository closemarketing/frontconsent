# FrontConsent — Documento de idea

> Estado: en desarrollo (extracción inicial completada) · Repositorio: `closemarketing/frontconsent` (privado)

## 1. Resumen

FrontConsent es un plugin de WordPress de gestión de consentimiento de cookies, enfocado en el cumplimiento de la normativa española (AEPD) y europea (RGPD / ePrivacy). Nace a partir del módulo de cookies que hoy vive dentro de **FrontBlocks**, donde queda escondido entre bloques, herramientas de imágenes y social login.

Sigue el mismo modelo que el resto de plugins de CLOSE: **freemium**, con una versión gratuita en WordPress.org y una versión Pro de pago, con licencias y crecimiento gestionados a través de **Nudge**.

## 2. Problema

- **Visibilidad.** Quien busca "cookies" o "consentimiento" en el directorio de WordPress.org no encuentra FrontBlocks, porque su ficha habla de bloques. El módulo de cookies no capta usuarios por sí mismo.
- **Posicionamiento confuso.** Un plugin multifunción es difícil de explicar y de vender. Un plugin que hace una sola cosa bien se entiende a la primera.
- **Hueco en el mercado español.** Los líderes son internacionales y el plugin histórico pensado para España ("Asesor de Cookies") está desactualizado y ya no cumple la normativa vigente según sus propias reseñas.

## 3. Mercado y competencia

| Plugin | Perfil | Observaciones |
|---|---|---|
| CookieYes | Líder, +1M instalaciones | Escaneo y registro en su nube; plan gratuito muy completo |
| Complianz | Muy asentado | Recomendado habitualmente en blogs de hosting españoles |
| Cookiebot | SaaS | Bloqueo automático, precio por dominio |
| ConsentX, WPConsent, otros | Nicho | Competencia creciente con Google Consent Mode v2 |
| Asesor de Cookies RGPD | Histórico en España | Abandonado / no adaptado a la guía actual de la AEPD |

**Conclusión:** no competimos de frente con CookieYes o Complianz. El diferencial es:

1. Cumplimiento fino de la **guía de la AEPD**, explicado y soportado en español.
2. **Todo en WordPress** (sin depender de una nube externa para el registro de consentimientos), bueno para privacidad y para vender a clientes sensibles.
3. Integración nativa con el ecosistema **FrontBlocks / FSE** y con los plugins de CLOSE.
4. Canal propio: la base de instalaciones de FrontBlocks y la agencia.

## 4. Requisitos normativos clave (AEPD)

- Rechazar debe costar **los mismos clics que aceptar**: botón "Rechazar todas" en la primera capa.
- Navegar, hacer scroll o permanecer en la página **no es consentimiento**.
- No se instalan cookies no necesarias **antes** de que el usuario decida.
- Configuración **por categorías** (necesarias, analíticas, marketing, preferencias).
- Las cookies **analíticas requieren consentimiento**.
- El usuario puede **cambiar su decisión** en cualquier momento (botón/enlace persistente).
- Caducidad del consentimiento configurable (máximo 24 meses).
- Enlaces claros a la política de cookies y privacidad.

## 5. Producto: Free vs Pro

### Gratuito (WordPress.org)

- Banner y panel de preferencias conformes con la AEPD.
- Categorías de cookies configurables.
- Bloqueo básico de scripts e iframes hasta el consentimiento.
- Integración con **WP Consent API**.
- Diseño adaptado a temas de bloques / FSE.
- Textos por defecto en español revisados según la guía de la AEPD.

### Pro

- **Escaneo automático** de cookies del sitio.
- **Registro de consentimientos** auditable (prueba de consentimiento) almacenado en la propia web.
- **Google Consent Mode v2** avanzado.
- **Multi-idioma** (compatibilidad con plugins de traducción).
- Generador de política de cookies.
- Bloqueo avanzado con placeholders (YouTube, Maps, redes sociales).
- Soporte prioritario en español.
- Licencias multisitio pensadas para agencias.

> Pendiente de validar: qué funciones exactas van en cada versión y el precio.

## 6. Relación con FrontBlocks

**Decisión propuesta:** sacar el módulo de cookies de FrontBlocks y que FrontConsent sea el único lugar donde se desarrolle el consentimiento. Se descarta mantener dos copias sincronizadas (por ejemplo, con un agente), porque divergen y multiplican el mantenimiento.

Plan de transición:

1. **Extraer** el módulo (ya está medio aislado) al repo de FrontConsent.
2. **Aviso en FrontBlocks**: si detecta el módulo de cookies en uso, muestra un aviso en el admin con botón para instalar FrontConsent desde WordPress.org sin salir del escritorio.
3. **Migración automática**: al activar FrontConsent, importa los ajustes de FrontBlocks y desactiva su módulo de cookies.
4. **Convivencia**: si ambos están activos, FrontBlocks cede siempre el control a FrontConsent.
5. Tras unas versiones de transición, **retirar** el módulo de FrontBlocks.

## 7. Arquitectura y stack

- Plugin independiente, slug propuesto `front-consent` (comprobado libre a fecha de este documento; reservar cuanto antes).
- Licencias, actualizaciones Pro y avisos de upgrade vía **Nudge** / `wp-plugin-license-manager`.
- El aviso de migración desde FrontBlocks puede reutilizar la capa de avisos de Nudge.
- CI/CD con GitHub Actions y despliegue a WordPress.org SVN, como el resto de plugins.
- Estándares de código de WordPress y tests con PHPUnit.
- Script del banner ligero, sin consultas a base de datos en carga de página y compatible con plugins de caché.

## 8. Roadmap inicial

- [ ] Reservar slug `front-consent` en WordPress.org.
- [x] Extraer el módulo de cookies de FrontBlocks (plugin `frontconsent` con su propio `frontconsent_settings`, banner, endpoints AJAX y página de ajustes).
- [ ] Revisar el banner contra la guía de la AEPD (heredado de FrontBlocks; pendiente auditoría específica AEPD).
- [x] Google Consent Mode v2 (heredado del módulo original).
- [ ] Integrar WP Consent API.
- [ ] Integrar Nudge (licencias y avisos).
- [x] Aviso + migración en FrontBlocks (`CookieNoticeDeprecationNotice` + `FrontConsent\Migration`, ejecuta al activar FrontConsent).
- [ ] Publicar versión gratuita en WordPress.org.
- [ ] Desarrollar funciones Pro (escaneo, registro de consentimientos).
- [ ] Lanzamiento Pro.

## 9. Preguntas abiertas

- ¿Reparto definitivo de funciones Free/Pro y precios?
- ¿El escaneo de cookies se hace en local o con un servicio propio?
- ¿Cuántas versiones de convivencia antes de retirar el módulo de FrontBlocks?
- ¿Se mantiene el nombre de marca "Front" para agrupar la familia de plugins?