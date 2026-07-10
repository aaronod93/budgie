// Web Awesome Pro — import only the components we actually use so the bundle
// stays lean. Add more component imports here as they're adopted.
//
// The Pro package installs from Web Awesome's private registry: web/.npmrc
// maps the @web.awesome.me scope to it and reads the auth token from the
// WEBAWESOME_NPM_TOKEN environment variable (locally: set it from web/.env
// before `npm install`; CI/Docker get it via secrets/build args).
import '@web.awesome.me/webawesome-pro/dist/styles/webawesome.css'
import '@web.awesome.me/webawesome-pro/dist/components/date-input/date-input.js'
import '@web.awesome.me/webawesome-pro/dist/components/combobox/combobox.js'
import '@web.awesome.me/webawesome-pro/dist/components/select/select.js'
import '@web.awesome.me/webawesome-pro/dist/components/option/option.js'

export default defineNuxtPlugin(() => {})
