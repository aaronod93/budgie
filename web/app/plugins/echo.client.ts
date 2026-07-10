import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Live budget updates over Reverb. Optional: an empty key (or Reverb simply
// not running) degrades to plain online behaviour.
export default defineNuxtPlugin(() => {
  const { reverbKey, reverbHost, reverbPort, reverbScheme } = useRuntimeConfig().public

  if (!reverbKey) return

  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

  const echo = new Echo({
    broadcaster: 'reverb',
    key: reverbKey as string,
    wsHost: reverbHost as string,
    wsPort: Number(reverbPort),
    wssPort: Number(reverbPort),
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
    // Authorize private channels through the API with Sanctum cookies.
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        apiFetch<{ auth: string }>('/broadcasting/auth', {
          method: 'POST',
          body: { socket_id: socketId, channel_name: channel.name },
        })
          .then(data => callback(null, data))
          .catch(error => callback(error instanceof Error ? error : new Error(String(error)), null))
      },
    }),
  })

  return { provide: { echo } }
})
