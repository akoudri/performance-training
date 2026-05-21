export type EventCategory =
  | 'concert'
  | 'festival'
  | 'theater'
  | 'conference'
  | 'exhibition'

export type EventStatus = 'draft' | 'published' | 'archived'

export interface Organizer {
  id: number
  company_name: string
  slug: string
  description?: string | null
  logo_path?: string | null
}

export interface Media {
  id: number
  type: 'image' | 'video'
  path: string
  url: string
  mime_type: string
  width?: number | null
  height?: number | null
  duration_seconds?: number | null
  position: number
  alt_text?: string | null
}

export interface Event {
  id: number
  slug: string
  title: string
  description: string
  category: EventCategory
  city: string
  country: string
  venue_name: string
  cover_image_path?: string | null
  cover_image_url?: string | null
  published_at?: string | null
  status: EventStatus
  organizer?: Organizer
  media?: Media[]
}

export interface TicketCategory {
  id: number
  name: string
  price_cents: number
  quota: number
  sold: number
  remaining: number
}

export interface EventSession {
  id: number
  starts_at: string
  ends_at: string
  doors_open_at?: string | null
  status: 'scheduled' | 'cancelled' | 'sold_out'
  ticket_categories: TicketCategory[]
}

export interface SingleResponse<T> {
  data: T
}

/**
 * Réponse paginée par curseur — format standardisé sur /api/v1/events
 * (cf. solution/j3-laravel). Les autres endpoints listing restent flat
 * en starter (volume <30 / utilisateur, pas de douleur mesurable).
 */
export interface CursorPaginatedResponse<T> {
  data: T[]
  meta: {
    next_cursor: string | null
    prev_cursor: string | null
    per_page: number
  }
}

export interface Order {
  id: number
  total_cents: number
  status: 'pending' | 'paid' | 'failed' | 'cancelled'
  paid_at?: string | null
  payment_reference?: string | null
  tickets?: Ticket[]
}

export interface Ticket {
  id: number
  code: string
  holder_name: string
  status: 'valid' | 'cancelled' | 'used'
  pdf_path?: string | null
  ticket_category: TicketCategory
  event_session: {
    id: number
    starts_at: string
    event: {
      id: number
      slug: string
      title: string
    }
  }
}
