<template>
    <transition name="slide-menu">
        <div class="sidebar" v-show="show">
            <div class="sidebar-header">Day {{stats ? stats.day : '-'}}</div>
            <div class="stat-container" v-if="stats">
                <table class="table">
                    <tbody>
                    <tr v-for="(user, index) in stats.highscore">
                        <td>
                            <div class="highscore_img">
                                <img class="king_icon" v-if="!index" src="/images/crown.png"/>
                                <img class="highscore_avatar" :src="getUserAvatar(user)"/>
                            </div>
                            <b class="highscore_name" :style="'color: ' + user.color">{{user.name}}</b>
                        </td>
                        <td>
                            <progress-line
                                :color="user.color"
                                :value="user.occupations"
                                :max="stats.highscore ? stats.highscore[0].occupations : 0" />
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div v-else>
                <div class="warning-label">Loading stats...</div>
            </div>

            <div class="sidebar-header second-header">Event log</div>

            <div class="events" v-if="events && events.data && events.data.length > 0">
                <div class="event" v-for="event in events.data">
                    <div class="event-header">
                        <div class="timestamp">{{event.timestamp}}</div>
                        <div>
                            <img v-if="event.extra && event.extra.territory_id"
                                 @click="scrollToTerritory(event.extra.territory_id)" src="/images/binoculars.png"/>
                            <a v-if="event.extra && event.extra.source_url"
                               :href="event.extra.source_url" target="_blank">
                                <img src="/images/picture.png"/>
                            </a>
                        </div>
                    </div>

                    <div class="event-text" v-html="event.text"></div>
                </div>

                <button v-if="events.next_page_url" @click="loadMoreEvents" class="btn" :disabled="loadingMoreEvents">
                    {{loadingMoreEvents ? 'Loading...' : 'Load more'}}
                </button>
            </div>
            <div v-else-if="loadingEvents" class="warning-label">Loading events...</div>
            <div class="warning-label" v-else>Whoops, seems nothing has happened here yet.</div>
        </div>
    </transition>
</template>

<script>
    import ProgressLine from './ProgressLine';

    export default {
        components:{
            ProgressLine,
        },

        data() {
            return {
                events: {data: []},
                stats: null,

                show: false,
                loadingEvents: true,
                loadingStats: true,
                loadingMoreEvents: false
            };
        },

        mounted() {
            Bus.$on('toggle-sidebar', () => {
                this.show = !this.show;
            });

            axios.get('/events')
                .then(response => {
                    this.events = response.data;
                    this.loadingEvents = false;
                })
                .catch(response => {
                    this.loadingEvents = false;
                });

            axios.get('/stats')
                .then(response => {
                    this.stats = response.data;
                    this.loadingStats = false;
                })
                .catch(response => {
                    this.loadingStats = false;
                });
        },

        methods: {
            loadMoreEvents: function() {
                if (!this.loadingMoreEvents) {
                    this.loadingMoreEvents = true;
                    axios.get(this.events.next_page_url)
                        .then(response => {
                            response.data.data = this.events.data.concat(response.data.data);
                            this.events = response.data;
                            this.loadingMoreEvents = false;
                        })
                        .catch(response => {
                            this.loadingMoreEvents = false;
                        });
                }
            },

            scrollToTerritory: function(territoryID) {
                Bus.$emit('scroll-to-territory', territoryID);
            },

            getUserAvatar: function(user) {
                let img = '/images/default_avatar.png';

                if (user && user.image) {
                    img = user.image;
                }

                return img;
            },
        }
    }
</script>