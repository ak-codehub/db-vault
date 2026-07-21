<script setup>
// Root component: layouts are chosen per-route inside each view (AppLayout
// for authenticated screens, GuestLayout for /login and /two-factor), so
// this shell only needs to host the router outlet.
</script>

<template>
    <router-view />
</template>
