# MOMENTUM Creative Studio core

Segundo slice de #126. Usa el BrandContext canónico de #228 y define briefs/variantes sin depender de proveedores o motores de generación.

## CreativeBrief
Pertenece a un Venture y BrandContext, referencia Campaign/Audience/Offer/CTA y declara un formato neutral. Las referencias usan `namespace:<32hex>`.

## CreativeVariant
Se liga exactamente al Brief/Venture/BrandContext/formato. Puede referenciar contenido, asset, hipótesis y métrica; cada ref opcional omitida se normaliza a null y se exige al menos content o asset. Estados: `draft`, `review`, `approved`, `rejected`, `archived`.

Una variante `approved` requiere provenance. `archived` conserva provenance y no representa borrado.

## VariantSet
Un set admite 0–26 variantes, rechaza IDs o keys duplicadas y se ordena de forma determinista por key/ID.

## Límite
No se guardan piezas o texto inline. No hay generación, publicación, gasto, adapters, persistencia, experiment runner, scheduler ni WorkItems. Esos pasos se añaden detrás de authority/budget/Factory en slices posteriores.
