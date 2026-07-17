// Promise-based confirmation dialog. Call from anywhere:
//
//   $confirm('Delete payee', 'This cannot be undone.', 'Delete', 'Cancel')
//     .then(() => doIt())
//     .catch(() => {}) // cancelled / dismissed
//
// The promise resolves on confirm and rejects on cancel, backdrop click or Esc.
// UiConfirmDialog (mounted once in app.vue) renders whatever is queued here.

interface ConfirmState {
  open: boolean
  title: string
  text: string
  confirmText: string
  rejectText: string
  danger: boolean
  resolve: (() => void) | null
  reject: ((reason?: unknown) => void) | null
}

export const confirmState = reactive<ConfirmState>({
  open: false,
  title: '',
  text: '',
  confirmText: 'Confirm',
  rejectText: 'Cancel',
  danger: false,
  resolve: null,
  reject: null,
})

export function $confirm(
  title: string,
  text: string,
  confirmText = 'Confirm',
  rejectText = 'Cancel',
  options: { danger?: boolean } = {},
): Promise<void> {
  // A dialog already showing loses: dismiss it so the new one takes over.
  confirmState.reject?.(new Error('superseded'))

  return new Promise<void>((resolve, reject) => {
    Object.assign(confirmState, {
      open: true,
      title,
      text,
      confirmText,
      rejectText,
      danger: options.danger ?? true,
      resolve,
      reject,
    })
  })
}
