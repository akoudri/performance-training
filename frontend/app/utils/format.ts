/** Affiche un prix stocké en centimes (ex. 8000 → "80,00 €"). */
export function formatPrice(cents: number): string {
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'EUR',
  }).format(cents / 100)
}

/** Formate une date ISO en français lisible (ex. "8 oct. 2026 à 19:30"). */
export function formatDateTime(iso: string): string {
  const date = new Date(iso)
  return new Intl.DateTimeFormat('fr-FR', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

/** Formate une date ISO en jour court (ex. "8 oct. 2026"). */
export function formatDate(iso: string): string {
  const date = new Date(iso)
  return new Intl.DateTimeFormat('fr-FR', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(date)
}

/** Libellé FR de la catégorie. */
export function categoryLabel(category: string): string {
  const map: Record<string, string> = {
    concert: 'Concert',
    festival: 'Festival',
    theater: 'Théâtre',
    conference: 'Conférence',
    exhibition: 'Exposition',
  }
  return map[category] ?? category
}
