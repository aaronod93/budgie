<script setup lang="ts">
// Page-level loading indicator: the Lil' Budgie piggy bank catching a stream
// of coins (from assets/img/piggy.svg, inlined so the CSS animations run and
// the canvas background can be dropped). Class/ID names are lb- prefixed so
// the embedded <style> — which applies document-wide by SVG spec — cannot
// collide with anything else.
withDefaults(defineProps<{
  size?: number
  label?: string
}>(), {
  size: 180,
  label: 'Loading…',
})
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-1" role="status" aria-live="polite">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="120 40 560 480" :width="size" aria-hidden="true">
      <defs>
        <g id="lb-coin-asset">
          <circle cx="0" cy="0" r="32" class="lb-coin-gold" stroke="#b27419" stroke-width="4" />
          <circle cx="0" cy="0" r="24" fill="none" stroke="#b27419" stroke-width="2" stroke-dasharray="6 4" opacity="0.5" />
          <text x="0" y="8" class="lb-coin-text">$</text>
        </g>
      </defs>

      <!-- Ambient ground shadow -->
      <ellipse cx="400" cy="485" rx="140" ry="16" class="lb-shadow" />

      <!-- Coin stream -->
      <g>
        <use href="#lb-coin-asset" id="lb-coin1" class="lb-coin" />
        <use href="#lb-coin-asset" id="lb-coin2" class="lb-coin" />
        <use href="#lb-coin-asset" id="lb-coin3" class="lb-coin" />
        <use href="#lb-coin-asset" id="lb-coin4" class="lb-coin" />
        <use href="#lb-coin-asset" id="lb-coin5" class="lb-coin" />
        <use href="#lb-coin-asset" id="lb-coin6" class="lb-coin" />
      </g>

      <!-- Piggy bank -->
      <g id="lb-piggy-bank">
        <path d="M 315 440 L 315 475 C 315 485, 350 485, 350 475 L 350 440 Z" class="lb-pig-dark" />
        <path d="M 450 440 L 450 475 C 450 485, 485 485, 485 475 L 485 440 Z" class="lb-pig-dark" />

        <path d="M 285 290 C 265 220, 335 225, 335 270 Z" class="lb-pig-body" stroke="#134e56" stroke-width="4.5" stroke-linejoin="round" />
        <path d="M 515 290 C 535 220, 465 225, 465 270 Z" class="lb-pig-body" stroke="#134e56" stroke-width="4.5" stroke-linejoin="round" />

        <path d="M 260 360
                 C 260 270, 320 250, 400 250
                 C 480 250, 540 270, 540 360
                 C 540 440, 480 460, 400 460
                 C 320 460, 260 440, 260 360 Z"
              class="lb-pig-body" stroke="#134e56" stroke-width="5.5" stroke-linejoin="round" />

        <path d="M 325 445 L 325 480 C 325 492, 365 492, 365 480 L 365 445 Z" class="lb-pig-body" stroke="#134e56" stroke-width="5" stroke-linejoin="round" />
        <path d="M 435 445 L 435 480 C 435 492, 475 492, 475 480 L 475 445 Z" class="lb-pig-body" stroke="#134e56" stroke-width="5" stroke-linejoin="round" />

        <ellipse cx="400" cy="256" rx="35" ry="8" class="lb-pig-dark" />

        <circle cx="345" cy="330" r="8" class="lb-pig-dark" />
        <circle cx="455" cy="330" r="8" class="lb-pig-dark" />

        <ellipse cx="400" cy="385" rx="45" ry="32" class="lb-pig-body" stroke="#134e56" stroke-width="5" />
        <ellipse cx="382" cy="385" rx="6" ry="10" class="lb-pig-dark" />
        <ellipse cx="418" cy="385" rx="6" ry="10" class="lb-pig-dark" />

        <path d="M 355 415 Q 400 445 445 415" fill="none" stroke="#134e56" stroke-width="5" stroke-linecap="round" />
      </g>
    </svg>
    <p v-if="label" class="text-sm text-mist-700">{{ label }}</p>
  </div>
</template>

<style>
/* Vue strips <style> tags inside templates, so the piggy's animation CSS
   lives here. Everything is lb- prefixed: this block is global. */
.lb-pig-body { fill: #2ba1ac; }
.lb-pig-dark { fill: #134e56; }
.lb-shadow { fill: #a2b7bf; opacity: 0.5; }
.lb-coin-gold { fill: #f1aa3a; }

.lb-coin-text {
  font-family: 'Arial Black', 'Impact', sans-serif;
  font-size: 22px;
  font-weight: 900;
  fill: #b27419;
  text-anchor: middle;
}

/* Continuous subtle reactive bounce for the piggy bank */
#lb-piggy-bank {
  animation: lbPigBounce 0.4s infinite ease-in-out;
  transform-origin: 400px 460px;
}

/* Staggered endless coin flow */
.lb-coin {
  animation-duration: 2.4s;
  animation-iteration-count: infinite;
  animation-timing-function: cubic-bezier(0.47, 0, 0.745, 0.715);
  opacity: 0;
}

#lb-coin1 { animation-name: lbDropCoin1; animation-delay: 0s; }
#lb-coin2 { animation-name: lbDropCoin2; animation-delay: 0.4s; }
#lb-coin3 { animation-name: lbDropCoin3; animation-delay: 0.8s; }
#lb-coin4 { animation-name: lbDropCoin4; animation-delay: 1.2s; }
#lb-coin5 { animation-name: lbDropCoin5; animation-delay: 1.6s; }
#lb-coin6 { animation-name: lbDropCoin6; animation-delay: 2s; }

@keyframes lbDropCoin1 {
  0%   { transform: translate(320px, -80px) rotate(-15deg) scale(1); opacity: 1; }
  85%  { transform: translate(390px, 220px) rotate(-5deg) scale(0.9); opacity: 1; }
  90%  { transform: translate(400px, 250px) rotate(0deg) scale(0.4); opacity: 0; }
  100% { transform: translate(400px, 250px); opacity: 0; }
}

@keyframes lbDropCoin2 {
  0%   { transform: translate(460px, -80px) rotate(35deg) scale(1); opacity: 1; }
  85%  { transform: translate(410px, 220px) rotate(10deg) scale(0.9); opacity: 1; }
  90%  { transform: translate(400px, 250px) rotate(0deg) scale(0.4); opacity: 0; }
  100% { transform: translate(400px, 250px); opacity: 0; }
}

@keyframes lbDropCoin3 {
  0%   { transform: translate(360px, -80px) rotate(-40deg) scale(1); opacity: 1; }
  85%  { transform: translate(395px, 220px) rotate(-15deg) scale(0.9); opacity: 1; }
  90%  { transform: translate(400px, 250px) rotate(0deg) scale(0.4); opacity: 0; }
  100% { transform: translate(400px, 250px); opacity: 0; }
}

@keyframes lbDropCoin4 {
  0%   { transform: translate(430px, -80px) rotate(20deg) scale(1); opacity: 1; }
  85%  { transform: translate(405px, 220px) rotate(5deg) scale(0.9); opacity: 1; }
  90%  { transform: translate(400px, 250px) rotate(0deg) scale(0.4); opacity: 0; }
  100% { transform: translate(400px, 250px); opacity: 0; }
}

@keyframes lbDropCoin5 {
  0%   { transform: translate(340px, -80px) rotate(-25deg) scale(1); opacity: 1; }
  85%  { transform: translate(392px, 220px) rotate(-8deg) scale(0.9); opacity: 1; }
  90%  { transform: translate(400px, 250px) rotate(0deg) scale(0.4); opacity: 0; }
  100% { transform: translate(400px, 250px); opacity: 0; }
}

@keyframes lbDropCoin6 {
  0%   { transform: translate(450px, -80px) rotate(45deg) scale(1); opacity: 1; }
  85%  { transform: translate(408px, 220px) rotate(12deg) scale(0.9); opacity: 1; }
  90%  { transform: translate(400px, 250px) rotate(0deg) scale(0.4); opacity: 0; }
  100% { transform: translate(400px, 250px); opacity: 0; }
}

@keyframes lbPigBounce {
  0%, 100% { transform: scale(1, 1); }
  50%      { transform: scale(1.03, 0.97) translateY(2px); }
}
</style>
