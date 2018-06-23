require('./bootstrap');

Vue.component('main-page', require('./components/Main.vue'));

const app = new Vue({
    el: '#app'
});
